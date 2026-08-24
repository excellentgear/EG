<?php
// src/store/store_QA_Abnormal_API.php
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
if (!isset($_SESSION['userName'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

include_once '../common/DBConnection.php';
include_once '../common/_config.php';
require_once '../common/qa_notify.php'; // 異常單 ↔ 通知系統共用模組
require_once '../common/attachment_lib.php'; // 附件標籤/轉檔/加註 共用函式庫

$conn = new DBConnection();
$db   = $conn->getPDO();
$userId = (int)($_SESSION['id'] ?? 0);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    ensureQASchema($db);
    eg_att_ensure_schema($db); // 附件標籤/說明/快取版欄位（首次自動補齊）

    switch ($action) {

        // ─── 編號產生 ─────────────────────────────────────────
        case 'get_next_no':
            echo json_encode(['success' => true, 'no' => generateNextNo($db)]);
            break;

        // ─── 建立異常單 ───────────────────────────────────────
        case 'create':
            $no           = trim($_POST['abnormal_order_no'] ?? '');
            $sourceType   = $_POST['source_type']   ?? '';
            $sourceId     = (int)($_POST['source_id'] ?? 0);
            $occDate      = $_POST['occurrence_date'] ?? date('Y-m-d');
            $foundUnit    = ($_POST['found_unit']  ?? '') ?: null;
            $phenomenon   = trim($_POST['abnormal_phenomenon'] ?? '');
            $typeId       = ($_POST['abnormal_type_id'] ?? '') !== '' ? (int)$_POST['abnormal_type_id'] : null;
            $defectCat    = ($_POST['defect_category'] ?? '') ?: null;
            $defectDetail = trim($_POST['defect_detail'] ?? '');
            // disposition 為 SET 欄位：多選須以逗號分隔（「、」在 STRICT 模式會寫入失敗）
            $disposition  = str_replace('、', ',', trim($_POST['disposition'] ?? ''));
            // 「轉總經理裁示」僅首要決策者可勾選
            $gmOptWarn = '';
            if ($disposition !== '') $disposition = qaStripGmOption($db, $userId, $disposition, $gmOptWarn);
            $dispNote     = trim($_POST['disposition_note'] ?? '');
            $qaPs         = trim($_POST['qa_ps'] ?? '');
            $sqty         = ($_POST['sqty'] ?? '') !== '' ? (int)$_POST['sqty'] : null;
            $depts        = json_decode($_POST['departments'] ?? '[]', true);
            $bomNo        = trim($_POST['bom_no'] ?? '') ?: null;
            $bomProcFids  = trim($_POST['bom_process_fids'] ?? '') ?: null;
            $respType     = ($_POST['responsible_type'] ?? '') ?: null;
            $respUnit     = trim($_POST['responsible_unit'] ?? '') ?: null;
            $respVendorId = trim($_POST['responsible_vendor_id'] ?? '') ?: null;
            $respDeptId   = ($_POST['responsible_dept_id'] ?? '') !== '' ? ((int)$_POST['responsible_dept_id'] ?: null) : null;
            $respPersonId = ($_POST['responsible_person_id'] ?? '') !== '' ? ((int)$_POST['responsible_person_id'] ?: null) : null;
            $tempKey      = trim($_POST['temp_key'] ?? '') ?: null;

            if (!$no) $no = generateNextNo($db);
            if (!$sourceType || !$sourceId) {
                echo json_encode(['success' => false, 'message' => '來源資訊缺失']); break;
            }

            // 異常處置方式/處置說明：僅具「回覆處置」權限(qa_disposition_reply)者可填，無權限則忽略傳入值
            if (($disposition !== '' || $dispNote !== '') && !qaHasFeature($db, $_SESSION['id'] ?? '', 'qa_disposition_reply')) {
                $disposition = ''; $dispNote = '';
            }

            $db->beginTransaction();
            $db->prepare("
                INSERT INTO qa_abnormal_order
                  (abnormal_order_no, source_type, source_id, occurrence_date,
                   responsible_unit, found_unit, abnormal_phenomenon, abnormal_type_id,
                   defect_category, defect_detail, disposition, disposition_note,
                   qa_ps, sqty,
                   bom_no, bom_process_fids, responsible_type,
                   responsible_vendor_id, responsible_dept_id, responsible_person_id,
                   created_by)
                VALUES
                  (:no,:st,:sid,:od,:ru,:fu,:ph,:tid,:dc,:dd,:disp,:dn,:qa,:sq,
                   :bn,:bpf,:rt,:rvi,:rdi,:rpi,:cb)
            ")->execute([
                ':no'=>$no, ':st'=>$sourceType, ':sid'=>$sourceId,
                ':od'=>$occDate, ':ru'=>$respUnit,
                ':fu'=>$foundUnit, ':ph'=>$phenomenon ?: null,
                ':tid'=>$typeId, ':dc'=>$defectCat,
                ':dd'=>$defectDetail ?: null, ':disp'=>$disposition ?: null,
                ':dn'=>$dispNote ?: null, ':qa'=>$qaPs ?: null,
                ':sq'=>$sqty,
                ':bn'=>$bomNo, ':bpf'=>$bomProcFids,
                ':rt'=>$respType, ':rvi'=>$respVendorId,
                ':rdi'=>$respDeptId, ':rpi'=>$respPersonId,
                ':cb'=>$userId
            ]);
            $orderId = (int)$db->lastInsertId();

            // 「附件 2 件以下直接顯示」勾選（有傳才寫，避免其他呼叫端誤清）
            if (isset($_POST['show_attach_inline'])) {
                $db->prepare("UPDATE qa_abnormal_order SET show_attach_inline=? WHERE id=?")
                   ->execute([!empty($_POST['show_attach_inline']) ? 1 : 0, $orderId]);
            }

            if (!empty($depts)) insertFlows($db, $orderId, $depts);
            if ($tempKey) linkTempAttachments($db, $tempKey, $orderId, $no);

            // 共同編輯者（部門或最多 5 位人員；會並列於自動產生公告的公告者，且可修改此單）
            if (isset($_POST['co_editors'])) qaSaveOrderEditors($db, $orderId, (string)$_POST['co_editors']);

            $db->commit();

            // 開單後建立通知（回覆部門=回覆回簽、通知/追蹤人員=已閱）；失敗不影響開單
            // 前端有傳名單(含空陣列)就照用；沒傳(如 IR_Track 舊表單)才套預設名單
            $notifyUsers   = isset($_POST['notify_users'])   ? (json_decode($_POST['notify_users'], true)   ?: []) : null;
            $followerUsers = isset($_POST['follower_users']) ? (json_decode($_POST['follower_users'], true) ?: []) : null;
            $notice = eg_qa_create_notice($db, $orderId, [
                'flows'          => is_array($depts) ? $depts : [],
                'notify_users'   => $notifyUsers,
                'follower_users' => $followerUsers,
                'reply_deadline' => trim($_POST['reply_deadline'] ?? '') ?: null,
                'created_by'     => $userId,
            ]);

            // 處置決策流程：開單時未判定 → 通知首要決策者（含代理人邏輯）；
            // 已判定且含「轉總經理裁示」→ 直接通知最終決策者
            $decideWarn = '';
            try {
                if ($disposition === '') {
                    $cfg = eg_qa_decision_setting($db);
                    if ((int)$cfg['primary'] > 0) eg_qa_notify_decision($db, $orderId, 'primary', $userId);
                    else $decideWarn = '尚未設定「首要決策者」，未發出處置判定通知（請至 品管檢驗頁 設定→處置決策設定）';
                } elseif (strpos($disposition, '轉總經理裁示') !== false) {
                    $cfg = eg_qa_decision_setting($db);
                    if ((int)$cfg['final'] > 0) eg_qa_notify_decision($db, $orderId, 'final', $userId);
                    else $decideWarn = '尚未設定「最終決策者」，未發出最終裁決通知（請至 品管檢驗頁 設定→處置決策設定）';
                }
            } catch (Throwable $e) { error_log('[store_QA_Abnormal_API] decide notify on create failed: ' . $e->getMessage()); }

            echo json_encode(['success' => true, 'id' => $orderId, 'no' => $no, 'event_id' => $notice['event_id'],
                              'decide_warn' => trim(implode('；', array_filter([$gmOptWarn, $decideWarn])))], JSON_UNESCAPED_UNICODE);
            break;

        // ─── 更新異常單基本資料 ───────────────────────────────
        case 'update':
            $orderId = (int)($_POST['id'] ?? 0);
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }

            // 修改權限：管理員/QC主管/開單人/共同編輯者/經核准的修改請求者；其他人一律擋下
            $perm = qaCanEditOrder($db, $userId, $orderId);
            if (!$perm['can']) {
                echo json_encode(['success'=>false, 'no_perm'=>true, 'message'=>'您沒有修改此異常單的權限，可向主管提出修改請求']);
                break;
            }

            $row = $db->prepare("SELECT abnormal_order_no FROM qa_abnormal_order WHERE id=?");
            $row->execute([$orderId]);
            $orderNo = $row->fetchColumn();

            // 編輯記錄：修改前快照
            $__qaBefore = qaOrderSnapshot($db, $orderId);

            // 「轉總經理裁示」僅首要決策者可勾選
            $updGmWarn = '';
            $updDisposition = str_replace('、', ',', trim($_POST['disposition'] ?? ''));
            if ($updDisposition !== '') $updDisposition = qaStripGmOption($db, $userId, $updDisposition, $updGmWarn);

            $fields = [
                'occurrence_date'       => ($_POST['occurrence_date'] ?? '')      ?: null,
                'responsible_unit'      => trim($_POST['responsible_unit'] ?? '') ?: null,
                'found_unit'            => ($_POST['found_unit'] ?? '')            ?: null,
                'abnormal_phenomenon'   => trim($_POST['abnormal_phenomenon'] ?? '') ?: null,
                'abnormal_type_id'      => ($_POST['abnormal_type_id'] ?? '') !== '' ? (int)$_POST['abnormal_type_id'] : null,
                'defect_category'       => ($_POST['defect_category'] ?? '')      ?: null,
                'defect_detail'         => trim($_POST['defect_detail'] ?? '')    ?: null,
                'disposition'           => $updDisposition ?: null,
                'disposition_note'      => trim($_POST['disposition_note'] ?? '') ?: null,
                'qa_ps'                 => trim($_POST['qa_ps'] ?? '')            ?: null,
                'sqty'                  => ($_POST['sqty'] ?? '') !== '' ? (int)$_POST['sqty'] : null,
                'gm_decision'           => ($_POST['gm_decision'] ?? '')          ?: null,
                'gm_note'               => trim($_POST['gm_note'] ?? '')          ?: null,
                'bom_no'                => trim($_POST['bom_no'] ?? '')           ?: null,
                'bom_process_fids'      => trim($_POST['bom_process_fids'] ?? '') ?: null,
                'responsible_type'      => ($_POST['responsible_type'] ?? '')     ?: null,
                'responsible_vendor_id' => trim($_POST['responsible_vendor_id'] ?? '') ?: null,
                'responsible_dept_id'   => ($_POST['responsible_dept_id'] ?? '') !== '' ? ((int)$_POST['responsible_dept_id'] ?: null) : null,
                'responsible_person_id' => ($_POST['responsible_person_id'] ?? '') !== '' ? ((int)$_POST['responsible_person_id'] ?: null) : null,
            ];
            // 「附件 2 件以下直接顯示」勾選（有傳才更新，避免其他呼叫端誤清）
            if (isset($_POST['show_attach_inline'])) $fields['show_attach_inline'] = !empty($_POST['show_attach_inline']) ? 1 : 0;
            // 無「回覆處置」權限者不可異動處置方式/處置說明（保留原值）
            if (!qaHasFeature($db, $_SESSION['id'] ?? '', 'qa_disposition_reply')) {
                unset($fields['disposition'], $fields['disposition_note']);
            }
            $sets = implode(', ', array_map(fn($k) => "`$k`=:$k", array_keys($fields)));
            $params = $fields;
            $params[':id'] = $orderId;
            $params[':ub'] = $userId;

            $db->beginTransaction();
            $db->prepare("UPDATE qa_abnormal_order SET $sets, updated_by=:ub, updated_at=NOW() WHERE id=:id")->execute($params);

            $newFlowDepts = []; // 本次新增的回覆部門/人員（用於補通知）
            if (isset($_POST['departments'])) {
                $depts = json_decode($_POST['departments'], true);
                $db->prepare("DELETE FROM qa_abnormal_order_flow WHERE abnormal_order_id=? AND status='Pending'")->execute([$orderId]);
                // 已回覆/處理中的流程保留原記錄；同部門不重複建立 Pending（避免重複要求回覆造成錯亂）
                $keep = $db->prepare("SELECT dept_id FROM qa_abnormal_order_flow WHERE abnormal_order_id=?");
                $keep->execute([$orderId]);
                $keepDeptIds = array_map('intval', $keep->fetchAll(PDO::FETCH_COLUMN));
                $insertDepts = [];
                foreach ((array)$depts as $d) {
                    if (in_array((int)($d['dept_id'] ?? 0), $keepDeptIds, true)) continue;
                    $insertDepts[] = $d;
                }
                if (!empty($insertDepts)) insertFlows($db, $orderId, $insertDepts);
                // 與修改前 Pending 名單比對，找出真正新增者（補通知用）
                if ($__qaBefore !== null && !empty($insertDepts)) $newFlowDepts = $insertDepts;
            }

            $tempKey = trim($_POST['temp_key'] ?? '') ?: null;
            if ($tempKey && $orderNo) linkTempAttachments($db, $tempKey, $orderId, $orderNo);

            // 共同編輯者（有傳才更新）
            if (isset($_POST['co_editors'])) qaSaveOrderEditors($db, $orderId, (string)$_POST['co_editors']);

            $db->commit();

            // 編輯記錄：前後快照（多人可編輯，須留完整記錄）
            qaLogOrderEdit($db, $orderId, 'update', $userId, trim($_POST['edit_reason'] ?? '') ?: null, $__qaBefore, qaOrderSnapshot($db, $orderId));
            $__updWarn = $updGmWarn;

            // 新增的回覆部門/人員：掛到主通知（要求=回覆+回簽）並推播，失敗不影響修改本身
            if (!empty($newFlowDepts)) {
                try {
                    $nev = (int)$db->query("SELECT notify_event_id FROM qa_abnormal_order WHERE id=" . (int)$orderId)->fetchColumn();
                    if ($nev) {
                        require_once '../push/push_send.php';
                        $oldR = eg_push_event_recipients($db, $nev);
                        $insT = $db->prepare("INSERT INTO live_event_target (live_event_id, target_type, target_id, mode)
                                              SELECT ?,?,?,? FROM DUAL
                                              WHERE NOT EXISTS (SELECT 1 FROM live_event_target WHERE live_event_id=? AND target_type=? AND target_id=?)");
                        foreach ($newFlowDepts as $d) {
                            if (!empty($d['user_id']))      { $tt = 'user'; $ti = (int)$d['user_id']; }
                            elseif (!empty($d['dept_id']))  { $tt = 'dept'; $ti = (int)$d['dept_id']; }
                            else continue;
                            $insT->execute([$nev, $tt, $ti, 'reply', $nev, $tt, $ti]);
                        }
                        $addedR = array_values(array_diff(eg_push_event_recipients($db, $nev), $oldR));
                        if (!empty($addedR)) eg_push_event_notify($db, $nev, $addedR, false);
                    }
                } catch (Throwable $e) {
                    error_log('[store_QA_Abnormal_API] notify new flow targets failed: ' . $e->getMessage());
                }
            }

            echo json_encode(['success' => true, 'warn' => $__updWarn], JSON_UNESCAPED_UNICODE);
            break;

        // ─── 取得單一異常單詳情 ───────────────────────────────
        case 'get_detail':
            $orderId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }

            $order = $db->prepare("
                SELECT o.*,
                       at.type_name AS abnormal_type_name,
                       u.user_cname  AS created_by_name,
                       ml.maker_id   AS vendor_name,
                       dept.name     AS responsible_dept_name,
                       pu.user_cname AS responsible_person_name
                FROM qa_abnormal_order o
                LEFT JOIN qa_abnormal_type at   ON at.type_id    = o.abnormal_type_id
                LEFT JOIN user u                ON u.id           = o.created_by
                LEFT JOIN maker_list ml         ON ml.maker_id_no = o.responsible_vendor_id
                LEFT JOIN department dept       ON dept.id        = o.responsible_dept_id
                LEFT JOIN user pu               ON pu.id          = o.responsible_person_id
                WHERE o.id = ?
            ");
            $order->execute([$orderId]);
            $data = $order->fetch(PDO::FETCH_ASSOC);
            if (!$data) { echo json_encode(['success'=>false,'message'=>'找不到異常單']); break; }

            $data['flow']        = getFlows($db, $orderId);
            $data['attachments'] = getOrderAttachments($db, $orderId);
            $data['co_editors']  = function_exists('eg_qa_order_editors') ? eg_qa_order_editors($db, $orderId) : [];
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        // ─── 取得某來源的異常單 ───────────────────────────────
        case 'get_by_source':
            $sourceType = $_POST['source_type'] ?? $_GET['source_type'] ?? '';
            $sourceId   = (int)($_POST['source_id'] ?? $_GET['source_id'] ?? 0);
            $stmt = $db->prepare("
                SELECT o.id, o.abnormal_order_no, o.source_type, o.is_closed,
                       at.type_name AS abnormal_type_name
                FROM qa_abnormal_order o
                LEFT JOIN qa_abnormal_type at ON at.type_id = o.abnormal_type_id
                WHERE o.source_type=? AND o.source_id=?
                ORDER BY o.created_at DESC
            ");
            $stmt->execute([$sourceType, $sourceId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ─── 流程：送交 ──────────────────────────────────────
        case 'flow_receive':
            $flowId     = (int)($_POST['flow_id'] ?? 0);
            $targetUser = ($_POST['target_user_id'] ?? '') !== '' ? (int)$_POST['target_user_id'] : null;
            if (!$flowId) { echo json_encode(['success'=>false,'message'=>'缺少flow_id']); break; }
            $db->prepare("
                UPDATE qa_abnormal_order_flow
                SET status='Received', receive_date=NOW(), updated_at=NOW()
                WHERE flow_id=? AND status='Pending'
            ")->execute([$flowId]);
            if ($targetUser) {
                $db->prepare("UPDATE qa_abnormal_order_flow SET user_id=? WHERE flow_id=?")->execute([$targetUser, $flowId]);
            }
            echo json_encode(['success' => true]);
            break;

        // ─── 流程：歸還 ──────────────────────────────────────
        case 'flow_return':
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $reply  = trim($_POST['reply_content'] ?? '');
            $db->prepare("
                UPDATE qa_abnormal_order_flow
                SET status='Returned', return_date=NOW(), reply_content=:r, updated_at=NOW()
                WHERE flow_id=:f AND status='Received'
            ")->execute([':r' => $reply ?: null, ':f' => $flowId]);

            // 回覆歸還 → 通知開單人＋追蹤人員（失敗不影響）
            $fi = $db->prepare("SELECT f.abnormal_order_id, d.name AS dept_name
                                FROM qa_abnormal_order_flow f LEFT JOIN department d ON d.id = f.dept_id
                                WHERE f.flow_id=?");
            $fi->execute([$flowId]);
            if ($frow = $fi->fetch(PDO::FETCH_ASSOC)) {
                eg_qa_notify_flow_return($db, (int)$frow['abnormal_order_id'], (string)$frow['dept_name'], $userId, $reply);
            }
            echo json_encode(['success' => true]);
            break;

        // ─── 流程：退回狀態 ───────────────────────────────────
        case 'flow_rollback':
            $flowId  = (int)($_POST['flow_id'] ?? 0);
            $target  = $_POST['target_status'] ?? 'Pending';
            if (!in_array($target, ['Pending','Received'])) $target = 'Pending';
            $clearFields = $target === 'Pending'
                ? "status='Pending', receive_date=NULL, return_date=NULL"
                : "status='Received', return_date=NULL";
            $db->prepare("UPDATE qa_abnormal_order_flow SET $clearFields, updated_at=NOW() WHERE flow_id=?")
               ->execute([$flowId]);
            echo json_encode(['success' => true]);
            break;

        // ─── 流程：再次送交 ───────────────────────────────────
        case 'flow_resend':
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $orig = $db->prepare("SELECT * FROM qa_abnormal_order_flow WHERE flow_id=?");
            $orig->execute([$flowId]);
            $f = $orig->fetch(PDO::FETCH_ASSOC);
            if ($f) {
                $db->prepare("
                    INSERT INTO qa_abnormal_order_flow
                      (abnormal_order_id, dept_id, user_id, include_mode, sort_order)
                    VALUES (?,?,?,?,?)
                ")->execute([$f['abnormal_order_id'], $f['dept_id'], $f['user_id'], $f['include_mode'], $f['sort_order']]);
            }
            echo json_encode(['success' => true]);
            break;

        // ─── 流程：儲存回覆內容 ───────────────────────────────
        case 'flow_save_reply':
            $flowId = (int)($_POST['flow_id'] ?? 0);
            $reply  = trim($_POST['reply_content'] ?? '');
            $db->prepare("UPDATE qa_abnormal_order_flow SET reply_content=?, updated_at=NOW() WHERE flow_id=?")
               ->execute([$reply ?: null, $flowId]);
            echo json_encode(['success' => true]);
            break;

        // ─── 部門設定 ─────────────────────────────────────────
        case 'get_dept_config':
            $db->exec("CREATE TABLE IF NOT EXISTS `qa_abnormal_dept_config` (
                `id` int AUTO_INCREMENT PRIMARY KEY,
                `dept_id` int NOT NULL,
                `include_mode` tinyint(1) DEFAULT 0,
                `sort_order` int DEFAULT 0
            ) DEFAULT CHARSET=utf8mb4");
            $stmt = $db->query("SELECT dept_id AS id, include_mode AS mode FROM qa_abnormal_dept_config ORDER BY sort_order");
            echo json_encode(['success' => true, 'config' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'save_dept_config':
            $depts = json_decode($_POST['depts'] ?? '[]', true);
            $db->beginTransaction();
            $db->exec("DELETE FROM qa_abnormal_dept_config");
            $ins = $db->prepare("INSERT INTO qa_abnormal_dept_config (dept_id, include_mode, sort_order) VALUES (?,?,?)");
            foreach ($depts as $i => $d) {
                $ins->execute([(int)$d['dept_id'], (int)($d['mode'] ?? 0), $i]);
            }
            $db->commit();
            echo json_encode(['success' => true]);
            break;

        // ─── 部門清單 ─────────────────────────────────────────
        case 'get_all_depts':
            $stmt = $db->query("SELECT id, name FROM department ORDER BY sort_order, id");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get_dept_users':
            $deptId = (int)($_POST['dept_id'] ?? 0);
            $mode   = (int)($_POST['mode'] ?? 0);
            echo json_encode(['success' => true, 'data' => getDeptUsers($db, $deptId, $mode)]);
            break;

        // ─── 人員模糊搜尋（通知/追蹤人員選擇用） ─────────────
        // 姓名／帳號／部門／職稱皆可比對；user_uname 為 latin1 欄位，
        // 直接 LIKE 中文會拋 3854 轉換錯誤，須先 CONVERT 成 utf8mb4
        case 'search_users':
            $kw = trim($_POST['keyword'] ?? '');
            if ($kw === '') { echo json_encode(['success'=>true,'data'=>[]]); break; }
            $kw2 = "%$kw%";
            $stmt = $db->prepare("
                SELECT u.id, u.user_cname,
                       (SELECT d.name FROM user_department_position_map m JOIN department d ON d.id = m.department_id
                        WHERE m.user_id = u.id AND m.is_main = 1 LIMIT 1) AS dept_name,
                       (SELECT p.name FROM user_department_position_map m2 JOIN position p ON p.id = m2.position_id
                        WHERE m2.user_id = u.id AND m2.is_main = 1 LIMIT 1) AS position_name
                FROM user u
                WHERE u.state = 1 AND (
                      u.user_cname LIKE ?
                   OR CONVERT(u.user_uname USING utf8mb4) LIKE ?
                   OR EXISTS (SELECT 1 FROM user_department_position_map m3
                              JOIN department d3 ON d3.id = m3.department_id
                              WHERE m3.user_id = u.id AND d3.name LIKE ?)
                   OR EXISTS (SELECT 1 FROM user_department_position_map m4
                              JOIN position p4 ON p4.id = m4.position_id
                              WHERE m4.user_id = u.id AND p4.name LIKE ?)
                )
                ORDER BY u.user_cname LIMIT 30
            ");
            $stmt->execute([$kw2, $kw2, $kw2, $kw2]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ─── 通知回覆狀態（異常單詳情唯讀顯示用） ────────────
        case 'get_notify_status':
            $orderId = (int)($_POST['abnormal_order_id'] ?? $_GET['abnormal_order_id'] ?? 0);
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少abnormal_order_id']); break; }
            eg_qa_notify_schema($db);
            $ev = $db->prepare("SELECT le.id, le.event_no, le.title, le.eventdate, le.reply_deadline
                                FROM qa_abnormal_order o JOIN live_event le ON le.id = o.notify_event_id
                                WHERE o.id = ?");
            $ev->execute([$orderId]);
            $event = $ev->fetch(PDO::FETCH_ASSOC);
            if (!$event) { echo json_encode(['success'=>true, 'event'=>null, 'targets'=>[], 'responses'=>[]]); break; }
            $eid = (int)$event['id'];

            $tg = $db->prepare("SELECT t.target_type, t.target_id, t.mode,
                                       CASE t.target_type WHEN 'dept' THEN d.name WHEN 'user' THEN u.user_cname ELSE '全體' END AS target_name
                                FROM live_event_target t
                                LEFT JOIN department d ON t.target_type='dept' AND d.id = t.target_id
                                LEFT JOIN user u ON t.target_type='user' AND u.id = t.target_id
                                WHERE t.live_event_id = ? ORDER BY t.id");
            $tg->execute([$eid]);

            $rp = $db->prepare("SELECT r.user_id, u.user_cname, r.read_at, r.signed_at, r.reply_content, r.replied_at
                                FROM live_event_response r LEFT JOIN user u ON u.id = r.user_id
                                WHERE r.live_event_id = ?
                                ORDER BY r.replied_at DESC, r.signed_at DESC, r.read_at DESC");
            $rp->execute([$eid]);

            $fw = $db->prepare("SELECT f.user_id, u.user_cname FROM qa_abnormal_follower f LEFT JOIN user u ON u.id = f.user_id WHERE f.abnormal_order_id = ?");
            $fw->execute([$orderId]);

            echo json_encode([
                'success'   => true,
                'event'     => $event,
                'targets'   => $tg->fetchAll(PDO::FETCH_ASSOC),
                'responses' => $rp->fetchAll(PDO::FETCH_ASSOC),
                'followers' => $fw->fetchAll(PDO::FETCH_ASSOC),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ─── 異常種類管理 ─────────────────────────────────────
        case 'get_abnormal_types':
            $stmt = $db->query("SELECT type_id, type_name FROM qa_abnormal_type WHERE is_active=1 ORDER BY sort_order, type_id");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'manage_abnormal_type':
            $sub = $_POST['sub_action'] ?? '';
            if ($sub === 'get_all') {
                $stmt = $db->query("SELECT type_id, type_name, is_active FROM qa_abnormal_type ORDER BY sort_order, type_id");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } elseif ($sub === 'add') {
                $name = trim($_POST['name'] ?? '');
                if (!$name) { echo json_encode(['success'=>false]); break; }
                $db->prepare("INSERT INTO qa_abnormal_type (type_name) VALUES (?)")->execute([$name]);
                echo json_encode(['success'=>true]);
            } elseif ($sub === 'update') {
                $db->prepare("UPDATE qa_abnormal_type SET type_name=?, is_active=? WHERE type_id=?")
                   ->execute([trim($_POST['name']), (int)$_POST['active'], (int)$_POST['id']]);
                echo json_encode(['success'=>true]);
            } elseif ($sub === 'delete') {
                $db->prepare("DELETE FROM qa_abnormal_type WHERE type_id=?")->execute([(int)$_POST['id']]);
                echo json_encode(['success'=>true]);
            }
            break;

        // ─── 結案 ─────────────────────────────────────────────
        case 'close':
            $orderId = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE qa_abnormal_order SET is_closed=1, closed_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$userId, $orderId]);
            echo json_encode(['success' => true]);
            break;

        // ─── BOM製程查詢 ─────────────────────────────────────
        case 'get_bom_processes':
            $bomNo = trim($_POST['bom_no'] ?? '');
            if (!$bomNo) { echo json_encode(['success'=>false,'message'=>'缺少bom_no']); break; }
            $stmt = $db->prepare("
                SELECT bi.bom_ing_fid, bi.processing_sequence,
                       bi.maker_id_no,
                       ml.maker_id AS maker_name
                FROM bom_ing bi
                LEFT JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no
                WHERE bi.bom = ? AND bi.is_consumed = 0
                ORDER BY bi.processing_sequence, bi.bom_sn
            ");
            $stmt->execute([$bomNo]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ─── 廠商模糊搜尋 ─────────────────────────────────────
        case 'search_vendors':
            $kw = trim($_POST['keyword'] ?? '');
            if (!$kw) { echo json_encode(['success'=>true,'data'=>[]]); break; }
            $kw2 = "%$kw%";
            $stmt = $db->prepare("
                SELECT maker_id_no, maker_id AS maker_name, maker_id_all
                FROM maker_list
                WHERE (maker_id LIKE ? OR maker_id_no LIKE ? OR maker_id_all LIKE ?)
                ORDER BY maker_id LIMIT 20
            ");
            $stmt->execute([$kw2, $kw2, $kw2]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ─── 附件上傳（圖片/PDF 直接入庫；Excel/Word 請走 att_upload 轉檔流程）───
        // 相容既有呼叫端：action 名稱與回傳欄位不變；新增 標籤/說明/原始檔名/大小 與格式驗證
        case 'upload_attachment':
            if (empty($_FILES['file']['tmp_name'])) {
                echo json_encode(['success'=>false,'message'=>'無附件']); break;
            }
            $fieldType = $_POST['field_type'] ?? '';
            if (!in_array($fieldType, ['qa_ps','phenomenon','defect_detail'])) {
                echo json_encode(['success'=>false,'message'=>'無效欄位']); break;
            }
            $v = eg_att_validate_upload($_FILES['file']);
            if (!$v['ok']) { echo json_encode(['success'=>false,'message'=>$v['msg']]); break; }
            if (in_array($v['ext'], eg_att_office_ext(), true)) {
                echo json_encode(['success'=>false,'message'=>'Excel/Word 需經轉檔流程，請使用附件按鈕重新上傳']); break;
            }
            $tempKey  = trim($_POST['temp_key'] ?? '');
            $orderId  = (int)($_POST['abnormal_order_id'] ?? 0);
            $orderNo  = trim($_POST['abnormal_order_no'] ?? '');
            $rootPath = getQASetting($db, 'attach_root_path') ?: 'Z:\\BOM\\ERP\\品管\\異常單附件';

            if ($orderId > 0 && $orderNo) {
                $folder       = rtrim($rootPath, '\\/') . DIRECTORY_SEPARATOR . $orderNo;
                $tempKeyForDb = null;
            } else {
                $folder       = rtrim($rootPath, '\\/') . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . $tempKey;
                $tempKeyForDb = $tempKey;
                $orderId      = null;
            }

            if (!is_dir($folder) && !mkdir($folder, 0777, true)) {
                echo json_encode(['success'=>false,'message'=>'無法建立資料夾：' . $folder]); break;
            }

            $origName = basename($_FILES['file']['name']);
            $ext      = $v['ext'];
            $base     = preg_replace('/[^\w\x{4e00}-\x{9fff}]/u', '_', pathinfo($origName, PATHINFO_FILENAME));
            $newName  = $base . '_' . time() . '.' . $ext;
            $destPath = $folder . DIRECTORY_SEPARATOR . $newName;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                echo json_encode(['success'=>false,'message'=>'檔案儲存失敗']); break;
            }

            // 標籤：無效或未選 → 預設標籤；附件說明選填
            $tagId = qaAttResolveTag($db, $_POST['tag_id'] ?? '');
            $desc  = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255, 'UTF-8') ?: null;

            $db->prepare("
                INSERT INTO qa_abnormal_attachments
                  (abnormal_order_id, temp_key, field_type, file_name, file_path, tag_id, description, original_filename, file_size, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$orderId, $tempKeyForDb, $fieldType, $newName, $destPath, $tagId, $desc, $origName, (int)$_FILES['file']['size'], $userId]);
            $newAttId = (int)$db->lastInsertId();
            eg_att_log($db, $userId, 'qa_att', $newAttId, 'tag_id', null, $tagId);
            // 已綁定異常單者立即產生角落標註快取版（暫存附件於開單/儲存時再產生）
            if ($orderId) eg_att_refresh_preview($db, 'abnormal', $newAttId);

            echo json_encode(['success'=>true,'id'=>$newAttId,'file_name'=>$newName,'tag_id'=>$tagId,'description'=>$desc]);
            break;

        // ─── 統一上傳入口（共用元件 EGAtt 使用）──────────────────
        // 圖片/PDF：直接入庫回傳 committed；Excel/Word：進暫存回傳 pending（轉 PDF 流程）
        case 'att_upload':
            $v = eg_att_validate_upload($_FILES['file'] ?? []);
            if (!$v['ok']) { echo json_encode(['success'=>false,'message'=>$v['msg']]); break; }
            $rootPath = getQASetting($db, 'attach_root_path') ?: 'Z:\\BOM\\ERP\\品管\\異常單附件';
            eg_att_pending_sweep($rootPath);
            if (in_array($v['ext'], eg_att_office_ext(), true)) {
                $p = eg_att_pending_create($rootPath, $_FILES['file'], $v['ext']);
                if (!$p) { echo json_encode(['success'=>false,'message'=>'暫存失敗，請確認附件儲存路徑可寫入']); break; }
                echo json_encode(['success'=>true, 'pending'=>$p]);
                break;
            }
            // 圖片/PDF：直接入庫
            $fieldType = $_POST['field_type'] ?? '';
            if (!in_array($fieldType, ['qa_ps','phenomenon','defect_detail'])) {
                echo json_encode(['success'=>false,'message'=>'無效欄位']); break;
            }
            $tempKey = trim($_POST['temp_key'] ?? '');
            $orderId = (int)($_POST['abnormal_order_id'] ?? 0);
            $orderNo = trim($_POST['abnormal_order_no'] ?? '');
            if ($orderId > 0 && $orderNo) {
                $folder = rtrim($rootPath, '\\/') . DIRECTORY_SEPARATOR . $orderNo;
                $tempKeyForDb = null;
            } else {
                $folder = rtrim($rootPath, '\\/') . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . $tempKey;
                $tempKeyForDb = $tempKey;
                $orderId = null;
            }
            if (!is_dir($folder) && !mkdir($folder, 0777, true)) {
                echo json_encode(['success'=>false,'message'=>'無法建立資料夾：' . $folder]); break;
            }
            $origName = basename($_FILES['file']['name']);
            $base     = preg_replace('/[^\w\x{4e00}-\x{9fff}]/u', '_', pathinfo($origName, PATHINFO_FILENAME));
            $newName  = $base . '_' . time() . '.' . $v['ext'];
            $destPath = $folder . DIRECTORY_SEPARATOR . $newName;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
                echo json_encode(['success'=>false,'message'=>'檔案儲存失敗']); break;
            }
            $tagId = qaAttResolveTag($db, $_POST['tag_id'] ?? '');
            $desc  = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255, 'UTF-8') ?: null;
            $db->prepare("
                INSERT INTO qa_abnormal_attachments
                  (abnormal_order_id, temp_key, field_type, file_name, file_path, tag_id, description, original_filename, file_size, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$orderId, $tempKeyForDb, $fieldType, $newName, $destPath, $tagId, $desc, $origName, (int)$_FILES['file']['size'], $userId]);
            $newAttId = (int)$db->lastInsertId();
            eg_att_log($db, $userId, 'qa_att', $newAttId, 'tag_id', null, $tagId);
            if ($orderId) eg_att_refresh_preview($db, 'abnormal', $newAttId);
            echo json_encode(['success'=>true, 'committed'=>['id'=>$newAttId, 'file_name'=>$newName, 'tag_id'=>$tagId, 'description'=>$desc]]);
            break;

        case 'att_convert':
            $rootPath = getQASetting($db, 'attach_root_path') ?: 'Z:\\BOM\\ERP\\品管\\異常單附件';
            $sheets = json_decode($_POST['sheets'] ?? '', true);
            $pdf = eg_att_pending_convert($rootPath, trim($_POST['upload_id'] ?? ''), is_array($sheets) && $sheets ? $sheets : null);
            if (!$pdf) { echo json_encode(['success'=>false,'message'=>'轉換失敗，該附件視同未完成上傳（詳見伺服器記錄）']); break; }
            echo json_encode(['success'=>true]);
            break;

        case 'att_preview': // GET；串流轉好的 PDF 給預覽 iframe
            $rootPath = getQASetting($db, 'attach_root_path') ?: 'Z:\\BOM\\ERP\\品管\\異常單附件';
            $meta = eg_att_pending_get($rootPath, trim($_GET['upload_id'] ?? ''));
            if (!$meta || empty($meta['pdf']) || !is_file($meta['pdf'])) { http_response_code(404); exit('not found'); }
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="preview.pdf"');
            readfile($meta['pdf']);
            exit;

        case 'att_commit': // 上傳者確認轉檔結果 → 正式入庫（PDF 入正式/暫存資料夾，原始檔刪除）
            $fieldType = $_POST['field_type'] ?? '';
            if (!in_array($fieldType, ['qa_ps','phenomenon','defect_detail'])) {
                echo json_encode(['success'=>false,'message'=>'無效欄位']); break;
            }
            $rootPath = getQASetting($db, 'attach_root_path') ?: 'Z:\\BOM\\ERP\\品管\\異常單附件';
            $uploadId = trim($_POST['upload_id'] ?? '');
            $meta = eg_att_pending_get($rootPath, $uploadId);
            if (!$meta || empty($meta['pdf']) || !is_file($meta['pdf'])) {
                echo json_encode(['success'=>false,'message'=>'找不到轉換結果，請重新上傳']); break;
            }
            $tempKey = trim($_POST['temp_key'] ?? '');
            $orderId = (int)($_POST['abnormal_order_id'] ?? 0);
            $orderNo = trim($_POST['abnormal_order_no'] ?? '');
            if ($orderId > 0 && $orderNo) {
                $folder = rtrim($rootPath, '\\/') . DIRECTORY_SEPARATOR . $orderNo;
                $tempKeyForDb = null;
            } else {
                $folder = rtrim($rootPath, '\\/') . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . $tempKey;
                $tempKeyForDb = $tempKey;
                $orderId = null;
            }
            $res = eg_att_pending_commit($rootPath, $uploadId, $folder);
            if (!$res) { echo json_encode(['success'=>false,'message'=>'檔案儲存失敗']); break; }
            $tagId = qaAttResolveTag($db, $_POST['tag_id'] ?? '');
            $desc  = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255, 'UTF-8') ?: null;
            $db->prepare("
                INSERT INTO qa_abnormal_attachments
                  (abnormal_order_id, temp_key, field_type, file_name, file_path, tag_id, description, original_filename, file_size, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$orderId, $tempKeyForDb, $fieldType, $res['stored_name'], $res['path'], $tagId, $desc, $res['orig_name'], $res['size'], $userId]);
            $newAttId = (int)$db->lastInsertId();
            eg_att_log($db, $userId, 'qa_att', $newAttId, 'tag_id', null, $tagId);
            if ($orderId) eg_att_refresh_preview($db, 'abnormal', $newAttId);
            echo json_encode(['success'=>true, 'committed'=>['id'=>$newAttId, 'file_name'=>$res['stored_name'], 'tag_id'=>$tagId, 'description'=>$desc, 'converted'=>true]]);
            break;

        case 'att_discard':
            $rootPath = getQASetting($db, 'attach_root_path') ?: 'Z:\\BOM\\ERP\\品管\\異常單附件';
            eg_att_pending_discard($rootPath, trim($_POST['upload_id'] ?? ''));
            echo json_encode(['success'=>true]);
            break;

        // ─── 修改附件的標籤/說明（寫異動 log、重建檢視快取版）─────
        case 'att_set_meta':
            $attachId = (int)($_POST['id'] ?? 0);
            $row = $db->prepare("SELECT * FROM qa_abnormal_attachments WHERE id=?");
            $row->execute([$attachId]);
            $att = $row->fetch(PDO::FETCH_ASSOC);
            if (!$att) { echo json_encode(['success'=>false,'message'=>'附件不存在']); break; }
            // 權限：上傳者本人 / 可修改該異常單者（管理員、QC主管、開單人、共同編輯者…）
            $allowed = ((int)$att['created_by'] === $userId);
            if (!$allowed && !empty($att['abnormal_order_id'])) {
                $perm = qaCanEditOrder($db, $userId, (int)$att['abnormal_order_id']);
                $allowed = !empty($perm['can']);
            }
            if (!$allowed) { echo json_encode(['success'=>false,'message'=>'您沒有修改此附件的權限']); break; }

            $updates = [];
            if (array_key_exists('tag_id', $_POST)) $updates['tag_id'] = qaAttResolveTag($db, $_POST['tag_id']);
            if (array_key_exists('description', $_POST)) $updates['description'] = mb_substr(trim((string)$_POST['description']), 0, 255, 'UTF-8') ?: null;
            if ($updates) {
                $set = []; $vals = [];
                foreach ($updates as $k=>$vv) { $set[]="`$k`=?"; $vals[]=$vv; }
                $vals[] = $attachId;
                $db->prepare("UPDATE qa_abnormal_attachments SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
                foreach ($updates as $k=>$vv) {
                    if ((string)($att[$k] ?? '') !== (string)($vv ?? '')) eg_att_log($db, $userId, 'qa_att', $attachId, $k, $att[$k] ?? null, $vv);
                }
                if (!empty($att['abnormal_order_id'])) eg_att_refresh_preview($db, 'abnormal', $attachId);
            }
            echo json_encode(['success'=>true]);
            break;

        // ─── 刪除附件 ─────────────────────────────────────────
        case 'delete_attachment':
            $attachId = (int)($_POST['id'] ?? 0);
            if (!$attachId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            $row = $db->prepare("SELECT * FROM qa_abnormal_attachments WHERE id=?");
            $row->execute([$attachId]);
            $att = $row->fetch(PDO::FETCH_ASSOC);
            if ($att) {
                if (!empty($att['file_path']) && file_exists($att['file_path'])) unlink($att['file_path']);
                if (!empty($att['preview_path']) && file_exists($att['preview_path'])) @unlink($att['preview_path']);
            }
            $db->prepare("DELETE FROM qa_abnormal_attachments WHERE id=?")->execute([$attachId]);
            echo json_encode(['success' => true]);
            break;

        // ─── 清除暫存附件 ─────────────────────────────────────
        case 'cleanup_temp_attachments':
            $tempKey = trim($_POST['temp_key'] ?? '');
            if (!$tempKey) { echo json_encode(['success'=>true]); break; }
            $stmt = $db->prepare("SELECT file_path FROM qa_abnormal_attachments WHERE temp_key=? AND abnormal_order_id IS NULL");
            $stmt->execute([$tempKey]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                if (file_exists($fp)) unlink($fp);
            }
            $db->prepare("DELETE FROM qa_abnormal_attachments WHERE temp_key=? AND abnormal_order_id IS NULL")->execute([$tempKey]);
            echo json_encode(['success' => true]);
            break;

        // ─── 取得附件清單 ─────────────────────────────────────
        case 'get_attachments':
            $orderId = (int)($_POST['abnormal_order_id'] ?? 0);
            $tempKey = trim($_POST['temp_key'] ?? '');
            if ($orderId > 0) {
                $stmt = $db->prepare("SELECT id, field_type, file_name, tag_id, description FROM qa_abnormal_attachments WHERE abnormal_order_id=? ORDER BY id");
                $stmt->execute([$orderId]);
            } elseif ($tempKey) {
                $stmt = $db->prepare("SELECT id, field_type, file_name, tag_id, description FROM qa_abnormal_attachments WHERE temp_key=? ORDER BY id");
                $stmt->execute([$tempKey]);
            } else {
                echo json_encode(['success'=>true,'data'=>[]]); break;
            }
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ─── 目前使用者的 QA 相關權限（前端控制欄位用） ──────
        case 'get_my_qa_perms':
            echo json_encode([
                'success' => true,
                'can_disposition' => qaHasFeature($db, $_SESSION['id'] ?? '', 'qa_disposition_reply'),
            ]);
            break;

        // ─── 回覆期限預設值（今天 + N 個工作天；N 存於設定，預設 10） ──
        // 工作天認定與 views/pages/calendar.php 相同：
        // 補班日(day_type='m')算工作天；週六日與休假日(day_type='s')不算
        case 'get_reply_deadline_default':
            $val  = getQASetting($db, 'qa_reply_deadline_workdays');
            $days = ($val !== null && (int)$val > 0) ? (int)$val : 10;
            $deadline = qaAddWorkdays($db, new DateTime('today'), $days);
            echo json_encode(['success' => true, 'days' => $days, 'date' => $deadline->format('Y-m-d')]);
            break;

        // ─── 系統設定 ─────────────────────────────────────────
        case 'get_setting':
            $key = trim($_POST['key'] ?? '');
            if (!$key) { echo json_encode(['success'=>false,'message'=>'缺少key']); break; }
            echo json_encode(['success'=>true,'value'=>getQASetting($db, $key)]);
            break;

        case 'save_setting':
            $key = trim($_POST['key'] ?? '');
            $val = trim($_POST['value'] ?? '');
            if (!$key) { echo json_encode(['success'=>false,'message'=>'缺少key']); break; }
            $db->prepare("
                INSERT INTO qa_system_settings (setting_key, setting_value, updated_by)
                VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()
            ")->execute([$key, $val, $userId]);
            echo json_encode(['success'=>true]);
            break;

        // ─── 異常單修改權限檢查（開啟修改畫面前呼叫）─────────────
        case 'check_edit_perm':
            $orderId = (int)($_POST['id'] ?? 0);
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            $perm = qaCanEditOrder($db, $userId, $orderId);
            $no = $db->prepare("SELECT abnormal_order_no FROM qa_abnormal_order WHERE id=?");
            $no->execute([$orderId]);
            echo json_encode(['success'=>true, 'can_edit'=>$perm['can'], 'why'=>$perm['why'], 'order_no'=>($no->fetchColumn() ?: '')]);
            break;

        // ─── 提出修改請求（無修改權限者 → 通知主管，原因必填）────
        case 'request_edit':
            $orderId = (int)($_POST['id'] ?? 0);
            $reason  = trim($_POST['reason'] ?? '');
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            if ($reason === '') { echo json_encode(['success'=>false,'message'=>'請填寫修改原因（必填）']); break; }
            $perm = qaCanEditOrder($db, $userId, $orderId);
            if ($perm['can']) { echo json_encode(['success'=>false,'message'=>'您已具備修改權限，無須提出請求']); break; }

            // 已有待審請求 → 更新原因重送；否則新增
            $ck = $db->prepare("SELECT id FROM qa_abnormal_edit_request WHERE abnormal_order_id=? AND requested_by=? AND status='pending' LIMIT 1");
            $ck->execute([$orderId, $userId]);
            $reqId = (int)$ck->fetchColumn();
            if ($reqId) {
                $db->prepare("UPDATE qa_abnormal_edit_request SET reason=?, created_at=NOW() WHERE id=?")->execute([$reason, $reqId]);
            } else {
                $db->prepare("INSERT INTO qa_abnormal_edit_request (abnormal_order_id, requested_by, reason) VALUES (?,?,?)")->execute([$orderId, $userId, $reason]);
                $reqId = (int)$db->lastInsertId();
            }
            qaLogOrderEdit($db, $orderId, 'request', $userId, $reason, null, null);
            $__sups = eg_qa_supervisors($db);
            eg_qa_notify_edit_request($db, $reqId); // 通知所有主管（角色勾選「認定為主管」，含快速開放修改按鈕）
            echo json_encode([
                'success'=>true, 'request_id'=>$reqId,
                'no_supervisor'=>empty($__sups),
                'message'=>empty($__sups) ? '請求已建立，但目前尚無任何角色被勾選「認定為主管」，無人會收到通知；請洽管理員於 品管檢驗頁 設定→權限設定 勾選' : '',
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ─── 取得修改請求內容（主管核准畫面用）───────────────────
        case 'get_edit_request':
            $reqId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$reqId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            $st = $db->prepare("SELECT r.*, o.abnormal_order_no, u.user_cname AS requester_name, au.user_cname AS approver_name
                                FROM qa_abnormal_edit_request r
                                JOIN qa_abnormal_order o ON o.id = r.abnormal_order_id
                                LEFT JOIN user u ON u.id = r.requested_by
                                LEFT JOIN user au ON au.id = r.approved_by
                                WHERE r.id = ?");
            $st->execute([$reqId]);
            $req = $st->fetch(PDO::FETCH_ASSOC);
            if (!$req) { echo json_encode(['success'=>false,'message'=>'找不到修改請求']); break; }
            $isSup = qaHasFeature($db, $userId, 'qc_supervisor');
            if (!$isSup && (int)$req['requested_by'] !== $userId) {
                echo json_encode(['success'=>false,'message'=>'僅主管（角色勾選「認定為主管」）或提出請求者本人可檢視此請求']); break;
            }
            $req['is_supervisor'] = $isSup;
            echo json_encode(['success'=>true, 'data'=>$req], JSON_UNESCAPED_UNICODE);
            break;

        // ─── 主管開放修改（核准後僅提出請求者本人可修改此異常單）──
        case 'approve_edit_request':
            $reqId = (int)($_POST['id'] ?? 0);
            if (!$reqId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            if (!qaHasFeature($db, $userId, 'qc_supervisor')) {
                echo json_encode(['success'=>false,'message'=>'僅主管（角色勾選「認定為主管」）可開放修改']); break;
            }
            $st = $db->prepare("SELECT * FROM qa_abnormal_edit_request WHERE id=?");
            $st->execute([$reqId]);
            $req = $st->fetch(PDO::FETCH_ASSOC);
            if (!$req) { echo json_encode(['success'=>false,'message'=>'找不到修改請求']); break; }
            if ($req['status'] === 'approved') { echo json_encode(['success'=>true, 'already'=>true]); break; }
            $db->prepare("UPDATE qa_abnormal_edit_request SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$userId, $reqId]);
            qaLogOrderEdit($db, (int)$req['abnormal_order_id'], 'grant', $userId,
                           '開放修改給 user#' . (int)$req['requested_by'] . '（僅該使用者可修改）', null, null);
            eg_qa_notify_edit_granted($db, $reqId, $userId); // 通知提出請求者（點開直接進修改畫面）
            echo json_encode(['success'=>true]);
            break;

        // ─── 處置判定人員（具 qa_disposition_reply 功能者）＋決策順序 ───
        // 順序固定規則：首要決策者第一、最終決策者最後，中間為 次要決策者(依設定順序)＋其餘判定人員（僅中間可調整）
        case 'get_disposition_deciders': {
            $rows = $db->query("SELECT DISTINCT u.id, u.user_cname FROM user_roles ur
                                JOIN role_features rf ON rf.role_id = ur.role_id
                                JOIN user u ON u.id = ur.user_id
                                WHERE rf.feature_code = 'qa_disposition_reply' AND u.state = 1")->fetchAll(PDO::FETCH_ASSOC);
            $cfgD = eg_qa_decision_setting($db);
            $orderIds = json_decode((string)getQASetting($db, 'qa_disposition_order'), true) ?: [];
            $orderIds = array_map('intval', $orderIds);
            $rankOf = function (int $id) use ($cfgD, $orderIds) {
                if ($id === (int)$cfgD['primary']) return -1;                 // 首要固定第一
                if ($id === (int)$cfgD['final'])   return PHP_INT_MAX;        // 最終固定最後
                $is = array_search($id, $cfgD['secondary'], true);
                if ($is !== false) return $is;                                 // 次要依設定順序
                $io = array_search($id, $orderIds, true);
                return 100000 + ($io === false ? 99999 : $io);                 // 其餘依既有順序
            };
            usort($rows, function ($a, $b) use ($rankOf) {
                $ra = $rankOf((int)$a['id']); $rb = $rankOf((int)$b['id']);
                return $ra === $rb ? strcmp($a['user_cname'], $b['user_cname']) : ($ra <=> $rb);
            });
            $roleOf = function (int $id) use ($cfgD) {
                if ($id === (int)$cfgD['primary']) return 'primary';
                if ($id === (int)$cfgD['final'])   return 'final';
                return in_array($id, $cfgD['secondary'], true) ? 'secondary' : '';
            };
            echo json_encode([
                'success' => true,
                'data' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['user_cname'], 'role' => $roleOf((int)$r['id'])], $rows),
                'can_sort' => qaHasFeature($db, $userId, 'qa_disposition_reply'),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'save_disposition_order': {
            if (!qaHasFeature($db, $userId, 'qa_disposition_reply')) {
                echo json_encode(['success'=>false,'message'=>'僅具「勾選/回覆異常處置」功能者可調整決策順序']); break;
            }
            $ids = json_decode($_POST['order'] ?? '[]', true);
            if (!is_array($ids)) { echo json_encode(['success'=>false,'message'=>'參數錯誤']); break; }
            // 首要/最終位置固定：即使前端誤傳也一律剔除，只存中間順序
            $cfgD = eg_qa_decision_setting($db);
            $ids = array_values(array_filter(array_map('intval', $ids),
                       fn($v) => $v > 0 && $v !== (int)$cfgD['primary'] && $v !== (int)$cfgD['final']));
            $sv = $db->prepare("
                INSERT INTO qa_system_settings (setting_key, setting_value, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()
            ");
            $sv->execute(['qa_disposition_order', json_encode($ids), $userId]);
            // 次要決策者的相對順序同步依新順序更新（成員不變，只換順序）
            $secOrdered = array_values(array_filter($ids, fn($v) => in_array($v, $cfgD['secondary'], true)));
            foreach ($cfgD['secondary'] as $s) { if (!in_array($s, $secOrdered, true)) $secOrdered[] = $s; }
            $sv->execute(['qa_secondary_deciders', json_encode($secOrdered), $userId]);
            echo json_encode(['success'=>true]);
            break;
        }

        // ─── 處置決策設定（品管單位部門／首要／最終決策者；主管設定）───
        case 'get_decision_setting': {
            $cfg = eg_qa_decision_setting($db);
            // 決策者候選 = 具 qa_disposition_reply 功能者，附部門與「是否屬品管單位」（預設建議用）
            $pool = $db->query("SELECT DISTINCT u.id, u.user_cname FROM user_roles ur
                                JOIN role_features rf ON rf.role_id = ur.role_id
                                JOIN user u ON u.id = ur.user_id
                                WHERE rf.feature_code = 'qa_disposition_reply' AND u.state = 1
                                ORDER BY u.user_cname")->fetchAll(PDO::FETCH_ASSOC);
            $qcSet = array_flip($cfg['qc_dept_ids']);
            foreach ($pool as &$p) {
                $ds = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=?");
                $ds->execute([(int)$p['id']]);
                $deptIds = array_map('intval', $ds->fetchAll(PDO::FETCH_COLUMN));
                $p['id'] = (int)$p['id'];
                $p['in_qc'] = false;
                foreach ($deptIds as $d) { if (isset($qcSet[$d])) { $p['in_qc'] = true; break; } }
                $p['deputies'] = eg_qa_active_deputies($db, $p['id']);
            }
            unset($p);
            $depts = $db->query("SELECT id, name FROM department ORDER BY level, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'qc_dept_ids' => $cfg['qc_dept_ids'],
                'primary' => $cfg['primary'],
                'final' => $cfg['final'],
                'secondary' => $cfg['secondary'],
                'pool' => $pool,
                'departments' => array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['name']], $depts),
                'can_manage' => qaHasFeature($db, $userId, 'qc_supervisor'),
                'is_primary' => ((int)$cfg['primary'] > 0 && (int)$cfg['primary'] === $userId), // 本人是否為首要決策者（「轉總經理裁示」僅其可勾）
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'save_decision_setting':
            if (!qaHasFeature($db, $userId, 'qc_supervisor')) {
                echo json_encode(['success'=>false,'message'=>'僅主管（角色勾選「認定為主管」）可設定處置決策']); break;
            }
            $qcDepts = json_decode($_POST['qc_dept_ids'] ?? '[]', true);
            $qcDepts = is_array($qcDepts) ? array_values(array_map('intval', $qcDepts)) : [];
            $primary = (int)($_POST['primary'] ?? 0);
            $final   = (int)($_POST['final'] ?? 0);
            if ($primary > 0 && $primary === $final) {
                echo json_encode(['success'=>false,'message'=>'首要決策者與最終決策者不可為同一人']); break;
            }
            // 次要決策者（有序；不可含首要/最終本人）
            $secondary = json_decode($_POST['secondary'] ?? '[]', true);
            $secondary = is_array($secondary) ? array_values(array_filter(array_map('intval', $secondary),
                             fn($v) => $v > 0 && $v !== $primary && $v !== $final)) : [];
            $sv = $db->prepare("INSERT INTO qa_system_settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
                                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=NOW()");
            // 品管部門已移到全站「組織角色綁定設定」(views/admin/org_role_setting.php 的 qc_dept)，
            // 本頁不再寫入 qa_qc_dept_ids，避免兩處設定打架（2026-08-03）。
            unset($qcDepts);
            $sv->execute(['qa_primary_decider', (string)$primary, $userId]);
            $sv->execute(['qa_final_decider', (string)$final, $userId]);
            $sv->execute(['qa_secondary_deciders', json_encode($secondary), $userId]);
            echo json_encode(['success'=>true]);
            break;

        // ─── 決策畫面情境（?decide_abnormal 開啟時呼叫）────────────
        case 'get_decide_context': {
            $orderId = (int)($_POST['id'] ?? 0);
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            $o = eg_qa_order_brief($db, $orderId);
            if (!$o) { echo json_encode(['success'=>false,'message'=>'找不到異常單']); break; }
            $finalAllowed   = eg_qa_decision_allowed_users($db, $orderId, 'final');
            $primaryAllowed = eg_qa_decision_allowed_users($db, $orderId, 'primary');
            $stage = !empty($finalAllowed) ? 'final' : (!empty($primaryAllowed) ? 'primary' : '');
            $allowedIds = $stage === 'final' ? $finalAllowed : $primaryAllowed;
            echo json_encode([
                'success' => true,
                'stage' => $stage, // ''=目前無待決策
                'allowed' => in_array($userId, $allowedIds, true),
                'order' => [
                    'id' => (int)$o['id'], 'no' => $o['abnormal_order_no'],
                    'phenomenon' => $o['abnormal_phenomenon'], 'source_desc' => $o['source_desc'],
                    'defect_detail' => $o['defect_detail'], 'qa_ps' => $o['qa_ps'],
                    'disposition' => $o['disposition'], 'disposition_note' => $o['disposition_note'],
                    'gm_decision' => $o['gm_decision'], 'gm_note' => $o['gm_note'],
                    'created_by_name' => $o['created_by_name'],
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ─── 處置判定（首要）／最終裁決：僅決策通知對象（決策者或其代理人）可執行 ───
        case 'decide': {
            $orderId = (int)($_POST['id'] ?? 0);
            $stage = ($_POST['stage'] ?? '') === 'final' ? 'final' : 'primary';
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            $allowedIds = eg_qa_decision_allowed_users($db, $orderId, $stage);
            if (!in_array($userId, $allowedIds, true)) {
                echo json_encode(['success'=>false,'message'=>'僅此階段的決策者或其代理人可判定（或此單已完成判定）']); break;
            }
            $__before = qaOrderSnapshot($db, $orderId);
            $warn = '';
            if ($stage === 'primary') {
                $disp = str_replace('、', ',', trim($_POST['disposition'] ?? ''));
                $note = trim($_POST['disposition_note'] ?? '');
                if ($disp === '') { echo json_encode(['success'=>false,'message'=>'請至少勾選一項處置方式']); break; }
                $db->prepare("UPDATE qa_abnormal_order SET disposition=?, disposition_note=?, updated_by=?, updated_at=NOW() WHERE id=?")
                   ->execute([$disp, $note ?: null, $userId, $orderId]);
                $needFinal = !empty($_POST['need_final']) || strpos($disp, '轉總經理裁示') !== false;
                qaLogOrderEdit($db, $orderId, 'decide', $userId, '首要處置判定' . ($needFinal ? '（送最終裁決）' : ''), $__before, qaOrderSnapshot($db, $orderId));
                eg_qa_close_decision_events($db, $orderId, 'primary', $userId);
                if ($needFinal) {
                    $cfg = eg_qa_decision_setting($db);
                    if ((int)$cfg['final'] > 0) eg_qa_notify_decision($db, $orderId, 'final', $userId);
                    else $warn = '尚未設定「最終決策者」，未發出最終裁決通知（請至 設定→處置決策設定）';
                }
            } else {
                $gmd = str_replace('、', ',', trim($_POST['gm_decision'] ?? ''));
                $gmn = trim($_POST['gm_note'] ?? '');
                if ($gmd === '' && $gmn === '') { echo json_encode(['success'=>false,'message'=>'請勾選裁決或填寫裁決說明']); break; }
                $db->prepare("UPDATE qa_abnormal_order SET gm_decision=?, gm_note=?, gm_decided_by=?, gm_decided_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")
                   ->execute([$gmd ?: null, $gmn ?: null, $userId, $userId, $orderId]);
                qaLogOrderEdit($db, $orderId, 'decide_final', $userId, '最終裁決', $__before, qaOrderSnapshot($db, $orderId));
                eg_qa_close_decision_events($db, $orderId, 'final', $userId);
            }
            // 通知開單人＋追蹤人員判定結果（失敗不影響）
            try {
                $o = eg_qa_order_brief($db, $orderId);
                $recips = eg_qa_reply_recipients($db, $o, $userId);
                if (!empty($recips)) {
                    $nm = $db->prepare("SELECT user_cname FROM user WHERE id=?");
                    $nm->execute([$userId]);
                    $who = $nm->fetchColumn() ?: ('#' . $userId);
                    $title = ($stage === 'final' ? '⚖️ 異常單 ' . $o['abnormal_order_no'] . ' 已完成最終裁決'
                                                  : '🖋 異常單 ' . $o['abnormal_order_no'] . ' 處置已判定') . '（' . $who . '）';
                    $lines = ['異常單號：' . $o['abnormal_order_no']];
                    if ($stage === 'final') { if ($o['gm_decision']) $lines[] = '最終裁決：' . $o['gm_decision']; if ($o['gm_note']) $lines[] = '裁決說明：' . $o['gm_note']; }
                    else { if ($o['disposition']) $lines[] = '處置方式：' . $o['disposition']; if ($o['disposition_note']) $lines[] = '處置說明：' . $o['disposition_note']; }
                    $targets = array_map(fn($u) => ['type'=>'user','id'=>$u,'mode'=>'read'], $recips);
                    eg_qa_insert_event($db, $orderId, $title, implode("\n", $lines), $targets, null, $userId);
                }
            } catch (Throwable $e) { error_log('[store_QA_Abnormal_API] decide result notify failed: ' . $e->getMessage()); }
            echo json_encode(['success'=>true, 'warn'=>$warn], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ─── 結案 / 取消結案（供未結案追蹤篩選；權限同修改）───────
        case 'close_order':
        case 'reopen_order':
            $orderId = (int)($_POST['id'] ?? 0);
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            $perm = qaCanEditOrder($db, $userId, $orderId);
            if (!$perm['can']) { echo json_encode(['success'=>false,'no_perm'=>true,'message'=>'您沒有結案/取消結案此異常單的權限']); break; }
            $closing = ($action === 'close_order');
            if ($closing) {
                $db->prepare("UPDATE qa_abnormal_order SET is_closed=1, closed_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")->execute([$userId, $orderId]);
            } else {
                $db->prepare("UPDATE qa_abnormal_order SET is_closed=0, closed_at=NULL, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$userId, $orderId]);
            }
            qaLogOrderEdit($db, $orderId, $closing ? 'close' : 'reopen', $userId, trim($_POST['reason'] ?? '') ?: null, null, null);
            echo json_encode(['success'=>true, 'is_closed'=>$closing ? 1 : 0]);
            break;

        // ─── 異常單編輯記錄 ───────────────────────────────────
        case 'get_order_edit_log':
            $orderId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$orderId) { echo json_encode(['success'=>false,'message'=>'缺少id']); break; }
            $st = $db->prepare("SELECT l.log_id, l.action, l.reason, l.before_json, l.after_json, l.changed_at, u.user_cname AS changed_by_name
                                FROM qa_abnormal_edit_log l
                                LEFT JOIN user u ON u.id = l.changed_by
                                WHERE l.abnormal_order_id = ? ORDER BY l.log_id DESC");
            $st->execute([$orderId]);
            echo json_encode(['success'=>true, 'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'message' => "未知 action: $action"]);
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[store_QA_Abnormal_API] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '資料庫錯誤', 'detail' => $e->getMessage()]);
}

// ─── 輔助函式 ───────────────────────────────────────────────

function ensureQASchema(PDO $db): void {
    $cols = $db->query("SHOW COLUMNS FROM qa_abnormal_order")->fetchAll(PDO::FETCH_COLUMN);
    $alters = [];
    if (!in_array('bom_no', $cols))               $alters[] = "ADD COLUMN `bom_no` VARCHAR(30) NULL";
    if (!in_array('bom_process_fids', $cols))      $alters[] = "ADD COLUMN `bom_process_fids` TEXT NULL";
    if (!in_array('responsible_type', $cols))      $alters[] = "ADD COLUMN `responsible_type` VARCHAR(10) NULL";
    if (!in_array('responsible_vendor_id', $cols)) $alters[] = "ADD COLUMN `responsible_vendor_id` VARCHAR(11) NULL";
    if (!in_array('responsible_dept_id', $cols))   $alters[] = "ADD COLUMN `responsible_dept_id` INT NULL";
    if (!in_array('responsible_person_id', $cols)) $alters[] = "ADD COLUMN `responsible_person_id` INT NULL";
    if (!empty($alters)) {
        $db->exec("ALTER TABLE qa_abnormal_order " . implode(', ', $alters));
    }

    $db->exec("CREATE TABLE IF NOT EXISTS `qa_abnormal_attachments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `abnormal_order_id` INT NULL,
        `temp_key` VARCHAR(64) NULL,
        `field_type` VARCHAR(20) NOT NULL,
        `file_name` VARCHAR(255) NOT NULL,
        `file_path` VARCHAR(500) NOT NULL,
        `created_by` INT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_order` (`abnormal_order_id`),
        INDEX `idx_temp` (`temp_key`)
    ) DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `qa_system_settings` (
        `setting_key` VARCHAR(50) PRIMARY KEY,
        `setting_value` TEXT NULL,
        `updated_by` INT NULL,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");

    // 共同編輯者／編輯記錄／修改請求
    $db->exec("CREATE TABLE IF NOT EXISTS `qa_abnormal_editor` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `abnormal_order_id` INT NOT NULL,
        `editor_type` ENUM('dept','user') NOT NULL,
        `editor_id` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_ord_editor` (`abnormal_order_id`, `editor_type`, `editor_id`)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='品質異常單 共同編輯者(部門或人員，人員最多5)'");
    $db->exec("CREATE TABLE IF NOT EXISTS `qa_abnormal_edit_log` (
        `log_id` INT AUTO_INCREMENT PRIMARY KEY,
        `abnormal_order_id` INT NOT NULL,
        `action` VARCHAR(20) NOT NULL,
        `reason` VARCHAR(255) NULL,
        `before_json` LONGTEXT NULL,
        `after_json` LONGTEXT NULL,
        `changed_by` INT NULL,
        `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_order` (`abnormal_order_id`)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='品質異常單編輯記錄(前後快照)'");
    $db->exec("CREATE TABLE IF NOT EXISTS `qa_abnormal_edit_request` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `abnormal_order_id` INT NOT NULL,
        `requested_by` INT NOT NULL,
        `reason` VARCHAR(255) NOT NULL,
        `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_order` (`abnormal_order_id`, `requested_by`, `status`)
    ) DEFAULT CHARSET=utf8mb4 COMMENT='品質異常單修改請求(核准後僅提出請求者可修改)'");

    eg_qa_notify_schema($db); // 通知相關欄位/資料表（live_event.ref_*、notify_event_id、追蹤人員）
}

function generateNextNo(PDO $db): string {
    $now    = new DateTime();
    $roc    = (int)$now->format('Y') - 1911;
    $mmdd   = $now->format('md');
    $prefix = 'Q' . str_pad($roc, 3, '0', STR_PAD_LEFT) . $mmdd;
    $stmt   = $db->prepare("SELECT abnormal_order_no FROM qa_abnormal_order WHERE abnormal_order_no LIKE ? ORDER BY abnormal_order_no DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, -3) + 1) : 1;
    return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
}

function insertFlows(PDO $db, int $orderId, array $depts): void {
    $ins = $db->prepare("INSERT INTO qa_abnormal_order_flow (abnormal_order_id, dept_id, user_id, include_mode, sort_order) VALUES (:oid,:did,:uid,:mode,:sort)");
    foreach ($depts as $i => $d) {
        $ins->execute([
            ':oid'  => $orderId,
            ':did'  => (int)$d['dept_id'],
            ':uid'  => !empty($d['user_id']) ? (int)$d['user_id'] : null,
            ':mode' => (int)($d['mode'] ?? 0),
            ':sort' => $i,
        ]);
    }
}

function getFlows(PDO $db, int $orderId): array {
    $stmt = $db->prepare("
        SELECT f.*, dep.name AS dept_name, u.user_cname AS receiver_name
        FROM qa_abnormal_order_flow f
        LEFT JOIN dept dep ON dep.id = f.dept_id
        LEFT JOIN user u   ON u.id   = f.user_id
        WHERE f.abnormal_order_id = ?
        ORDER BY f.sort_order, f.flow_id
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDeptUsers(PDO $db, int $deptId, int $mode): array {
    if ($mode === 1) {
        $stmt = $db->prepare("
            SELECT u.id, u.user_cname, p.name AS position_name, udp.is_main
            FROM user_department_position_map udp
            JOIN user u ON u.id = udp.user_id
            LEFT JOIN position p ON p.id = udp.position_id
            WHERE (udp.department_id = ? OR udp.department_id IN (SELECT id FROM department WHERE parent_id = ?))
              AND u.state = 1
            ORDER BY p.sort_order, u.user_cname
        ");
        $stmt->execute([$deptId, $deptId]);
    } elseif ($mode === 2) {
        $stmt = $db->prepare("
            SELECT u.id, u.user_cname, p.name AS position_name, udp.is_main
            FROM user_department_position_map udp
            JOIN user u ON u.id = udp.user_id
            LEFT JOIN position p ON p.id = udp.position_id
            WHERE udp.department_id IN (SELECT id FROM department WHERE parent_id = ?)
              AND udp.is_main = 1 AND u.state = 1
            ORDER BY p.sort_order, u.user_cname
        ");
        $stmt->execute([$deptId]);
    } else {
        $stmt = $db->prepare("
            SELECT u.id, u.user_cname, p.name AS position_name, udp.is_main
            FROM user_department_position_map udp
            JOIN user u ON u.id = udp.user_id
            LEFT JOIN position p ON p.id = udp.position_id
            WHERE udp.department_id = ? AND u.state = 1
            ORDER BY p.sort_order, u.user_cname
        ");
        $stmt->execute([$deptId]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getOrderAttachments(PDO $db, int $orderId): array {
    $stmt = $db->prepare("SELECT id, field_type, file_name FROM qa_abnormal_attachments WHERE abnormal_order_id=? ORDER BY id");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getQASetting(PDO $db, string $key): ?string {
    $stmt = $db->prepare("SELECT setting_value FROM qa_system_settings WHERE setting_key=?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : null;
}

// ─── RBAC：讀取使用者功能（沿用 roles/role_features/user_roles 框架，
//     與 views/QC/inspection_combined_prototype.php 同邏輯：完全未指派角色→全權避免鎖死）───
function qaUserFeatures(PDO $db, $userId): array {
    try {
        $uid = trim((string)$userId);
        $chk = $db->prepare("SELECT 1 FROM user_roles WHERE user_id=? LIMIT 1");
        $chk->execute([$uid]);
        if ((bool)$chk->fetchColumn()) {
            $st = $db->prepare("SELECT DISTINCT rf.feature_code FROM user_roles ur JOIN role_features rf ON rf.role_id=ur.role_id WHERE ur.user_id=?");
            $st->execute([$uid]);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    } catch (Exception $e) { /* 表可能不存在 */ }
    return ['all'];
}
function qaHasFeature(PDO $db, $userId, string $code): bool {
    $f = qaUserFeatures($db, $userId);
    return in_array('all', $f, true) || in_array($code, $f, true);
}

// ─── 異常單修改權限 ──────────────────────────────────────────
// 可修改者：管理員(all)／主管(角色勾選「認定為主管」qc_supervisor)／開單人／共同編輯者(含本人部門)／經主管核准修改請求的本人
function qaCanEditOrder(PDO $db, int $uid, int $orderId): array {
    $st = $db->prepare("SELECT created_by FROM qa_abnormal_order WHERE id=?");
    $st->execute([$orderId]);
    $createdBy = $st->fetchColumn();
    if ($createdBy === false) return ['can' => false, 'why' => 'not_found'];
    if (qaHasFeature($db, $uid, 'qc_supervisor')) return ['can' => true, 'why' => 'supervisor'];
    if ((int)$createdBy === $uid) return ['can' => true, 'why' => 'creator'];
    if (function_exists('eg_qa_user_is_order_editor') && eg_qa_user_is_order_editor($db, $orderId, $uid)) return ['can' => true, 'why' => 'co_editor'];
    $rq = $db->prepare("SELECT 1 FROM qa_abnormal_edit_request WHERE abnormal_order_id=? AND requested_by=? AND status='approved' LIMIT 1");
    $rq->execute([$orderId, $uid]);
    if ($rq->fetchColumn()) return ['can' => true, 'why' => 'granted'];
    return ['can' => false, 'why' => 'no_perm'];
}

// 異常單編輯記錄（前後快照；避免多人共同編輯後重要資訊被改而無從查起）
function qaOrderSnapshot(PDO $db, int $orderId): ?array {
    $st = $db->prepare("SELECT * FROM qa_abnormal_order WHERE id=?");
    $st->execute([$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    try {
        $ed = $db->prepare("SELECT editor_type, editor_id FROM qa_abnormal_editor WHERE abnormal_order_id=? ORDER BY id");
        $ed->execute([$orderId]);
        $row['co_editors'] = array_map(fn($e) => $e['editor_type'] . '-' . $e['editor_id'], $ed->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { $row['co_editors'] = []; }
    return $row;
}
function qaLogOrderEdit(PDO $db, int $orderId, string $action, $uid, ?string $reason, ?array $before, ?array $after): void {
    try {
        $db->prepare("INSERT INTO qa_abnormal_edit_log (abnormal_order_id, action, reason, before_json, after_json, changed_by) VALUES (?,?,?,?,?,?)")
           ->execute([
               $orderId, $action, $reason,
               ($before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null),
               ($after  !== null ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null),
               ((int)$uid ?: null),
           ]);
    } catch (Throwable $e) { error_log('[qa_edit_log] ' . $e->getMessage()); }
}

// 「轉總經理裁示」僅首要決策者本人可勾選（開單/修改表單一律過濾；
//  決策畫面的首要判定階段(decide)不經此函式，代理人代首要決策者行使時仍可勾）
function qaStripGmOption(PDO $db, int $uid, string $disposition, string &$warn): string {
    if ($disposition === '' || strpos($disposition, '轉總經理裁示') === false) return $disposition;
    $cfg = eg_qa_decision_setting($db);
    if ($uid > 0 && (int)$cfg['primary'] === $uid) return $disposition;
    $parts = array_filter(array_map('trim', explode(',', $disposition)), fn($p) => $p !== '' && $p !== '轉總經理裁示');
    $warn = '「轉總經理裁示」僅首要決策者可勾選，已自動移除該選項';
    return implode(',', $parts);
}

// 儲存異常單共同編輯者（JSON [{type:'dept'|'user', id:N}]，人員最多 5 位）
function qaSaveOrderEditors(PDO $db, int $orderId, string $editorsJson): void {
    $list = json_decode($editorsJson, true);
    if (!is_array($list)) $list = [];
    $db->prepare("DELETE FROM qa_abnormal_editor WHERE abnormal_order_id=?")->execute([$orderId]);
    $ins = $db->prepare("INSERT IGNORE INTO qa_abnormal_editor (abnormal_order_id, editor_type, editor_id) VALUES (?,?,?)");
    $userCount = 0;
    foreach ($list as $e) {
        $type = ($e['type'] ?? '') === 'dept' ? 'dept' : 'user';
        $id = (int)($e['id'] ?? 0);
        if ($id <= 0) continue;
        if ($type === 'user') { if ($userCount >= 5) continue; $userCount++; }
        $ins->execute([$orderId, $type, $id]);
    }
}

// ─── 工作天：載入 [from, to] 區間內的休假日('s')與補班日('m')集合 ───
// 資料來源與 calendar.php 相同（evenement + event_category.day_type），
// 含重複事件展開；事件結束日視為含當天，無結束日視為單日
function qaLoadDayTypeMaps(PDO $db, DateTime $from, DateTime $to): array {
    $holidays = []; $makeups = [];
    try {
        $stmt = $db->query("SELECT e.start, e.end, e.recurrence_type, e.recurrence_count, ec.day_type
                            FROM evenement e JOIN event_category ec ON e.category_id = ec.id
                            WHERE ec.day_type IN ('s','m')");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return [$holidays, $makeups]; }

    $ivMap = ['daily' => 'P1D', 'weekly' => 'P1W', 'monthly' => 'P1M', 'yearly' => 'P1Y'];
    foreach ($rows as $row) {
        $occurrences = [[$row['start'], $row['end']]];
        $rt = $row['recurrence_type'] ?? null;
        $rc = (int)($row['recurrence_count'] ?? 0);
        if ($rt && $rc > 0 && isset($ivMap[$rt])) {
            try {
                $s = new DateTime($row['start']);
                $e = !empty($row['end']) ? new DateTime($row['end']) : null;
                for ($i = 0; $i < $rc; $i++) {
                    $s->add(new DateInterval($ivMap[$rt]));
                    if ($e) $e->add(new DateInterval($ivMap[$rt]));
                    $occurrences[] = [$s->format('Y-m-d'), $e ? $e->format('Y-m-d') : null];
                }
            } catch (Exception $e2) { /* 日期格式錯誤則略過重複展開 */ }
        }
        foreach ($occurrences as [$os, $oe]) {
            try {
                $cur = new DateTime(substr((string)$os, 0, 10));
                $end = $oe ? new DateTime(substr((string)$oe, 0, 10)) : clone $cur;
            } catch (Exception $e3) { continue; }
            if ($end < $from || $cur > $to) continue;
            while ($cur <= $end && $cur <= $to) {
                if ($cur >= $from) {
                    $d = $cur->format('Y-m-d');
                    if ($row['day_type'] === 's') $holidays[$d] = true;
                    else $makeups[$d] = true;
                }
                $cur->modify('+1 day');
            }
        }
    }
    return [$holidays, $makeups];
}

// ─── 工作天：回傳 base 之後第 N 個工作天的日期 ───
function qaAddWorkdays(PDO $db, DateTime $base, int $days): DateTime {
    $to = clone $base;
    $to->modify('+' . max(60, $days * 4) . ' days'); // 視窗足以涵蓋假日
    [$hol, $mk] = qaLoadDayTypeMaps($db, clone $base, $to);
    $d = clone $base;
    $count = 0; $guard = 0;
    while ($count < $days && $guard++ < 1500) {
        $d->modify('+1 day');
        $ds = $d->format('Y-m-d');
        $w  = (int)$d->format('w'); // 0=週日 6=週六
        // 與 calendar.php 相同：補班日算工作天；否則須非週末且非休假日
        if (isset($mk[$ds]) || (($w !== 0 && $w !== 6) && !isset($hol[$ds]))) $count++;
    }
    return $d;
}

function linkTempAttachments(PDO $db, string $tempKey, int $orderId, string $orderNo): void {
    $rootPath    = getQASetting($db, 'attach_root_path') ?: 'Z:\\BOM\\ERP\\品管\\異常單附件';
    $orderFolder = rtrim($rootPath, '\\/') . DIRECTORY_SEPARATOR . $orderNo;

    $stmt = $db->prepare("SELECT id, file_path, file_name FROM qa_abnormal_attachments WHERE temp_key=? AND abnormal_order_id IS NULL");
    $stmt->execute([$tempKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return;

    if (!is_dir($orderFolder)) mkdir($orderFolder, 0777, true);

    $upd = $db->prepare("UPDATE qa_abnormal_attachments SET abnormal_order_id=?, temp_key=NULL, file_path=? WHERE id=?");
    foreach ($rows as $att) {
        $newPath = $orderFolder . DIRECTORY_SEPARATOR . $att['file_name'];
        if (file_exists($att['file_path']) && $att['file_path'] !== $newPath) {
            rename($att['file_path'], $newPath);
            $tempDir = dirname($att['file_path']);
            if (is_dir($tempDir) && count(scandir($tempDir)) <= 2) @rmdir($tempDir);
        }
        $upd->execute([$orderId, $newPath, $att['id']]);
        // 綁定完成 → 產生角落標註檢視快取版（失敗降級，不影響開單）
        try { eg_att_refresh_preview($db, 'abnormal', (int)$att['id']); }
        catch (Throwable $e) { error_log('[store_QA_Abnormal_API] preview on link failed: ' . $e->getMessage()); }
    }
}

/** 附件標籤解析：無效/未選 → 該 scope 預設標籤 */
function qaAttResolveTag(PDO $db, $tagIdRaw): ?int {
    $tagId = null;
    if ($tagIdRaw !== '' && $tagIdRaw !== null) {
        $t = eg_att_tag_row($db, (int)$tagIdRaw);
        if ($t && $t['scope'] === 'abnormal' && (int)$t['is_active'] === 1) $tagId = (int)$t['id'];
    }
    return $tagId !== null ? $tagId : eg_att_default_tag_id($db, 'abnormal');
}
