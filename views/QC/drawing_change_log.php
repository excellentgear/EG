<?php
// =============================================================================
// views/QC/drawing_change_log.php   圖面變更紀錄（AS 2-PD-01-07 圖面變更簽收單）
// -----------------------------------------------------------------------------
// 為什麼要這一頁：客戶改圖後，雖然還是「同料號同製程」，但檢驗內容可能整組不同。
// 現行 views/pm/drawing_rename.php 的「作廢版」只處理『檔案層』（舊圖蓋作廢章、換上新圖），
// 沒有留下「改了什麼、從哪個製程開始受影響、誰知道了、檢驗項目改好了沒」的紀錄。
// 本頁補的就是這一段管理紀錄，並串起三件事：
//   ① 自動把該料號的檢驗標準複製成「新版次」→ QC 只要改動到的尺寸，舊版保留可追溯
//   ② 通知相關人員簽收（live_event ref_type='DWG'，行動型：沒簽會一直顯示）
//   ③ 檢驗表 2.0 開啟受影響製程時跳提醒，QC 確認「已依新版次更新檢驗項目」後才消
//
// 影響範圍：可指定「從哪個製程開始」（例如只改精加工尺寸，粗車不受影響）；
//           留白＝該料號所有製程都提醒。判斷時用該 BOM 的 processing_sequence 比大小。
// =============================================================================
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/rbac.php';

if (empty($_SESSION['qc_csrf'])) { $_SESSION['qc_csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['qc_csrf'];
$AS_DOC_NO = '2-PD-01-07';   // 綁定的 AS 表單編號（圖面變更簽收單）

// -----------------------------------------------------------------------------
// AJAX 後端
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    include_once '../../src/common/dwg_notify.php';
    include_once '../../src/common/dwg_change_lib.php';   // 判定與建立變更的唯一實作點
    include_once '../../src/common/confirm_password_lib.php';   // 管理員改他人紀錄要驗操作確認密碼

    $pdo = (new DBConnection())->getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $uid   = trim((string)($_SESSION['id'] ?? ''));
    $feats = rbac_user_features($pdo, (int)$uid);
    $has   = function ($c) use ($feats) { return in_array('all', $feats, true) || in_array($c, $feats, true); };
    $canView   = $has('qc_view_readonly') || $has('qc_fill_inspection') || $has('qc_manage_settings') || $has('qc_edit_history') || $has('all');
    $canManage = $has('qc_manage_settings') || $has('all');   // 建立/修改圖面變更＝管理檢驗設定權限
    $isAdmin   = in_array('all', $feats, true);                // 管理員：可改他人填的紀錄（需操作確認密碼）
    $act = $_POST['action'];

    try {
        $WRITE = ['save_change', 'ack_change', 'close_change', 'delete_change', 'submit_change',
                  'save_default_ack', 'auto_from_attach'];
        if (in_array($act, $WRITE, true)) {
            // 先確認人還在。session 檔被 PHP 的 GC 掃掉時（見 src/common/_config.php 的防護說明），
            // 這一行上面的 $_SESSION['qc_csrf'] 會在同一個請求裡重新產生，token 比對必定不過——
            // 但那其實是「已經被登出」不是「CSRF 攻擊」。訊息講錯的話使用者只會一直重整、卻永遠存不進去。
            if ($uid === '') throw new Exception('登入已逾時，請重新登入後再儲存（您填的內容還在，重新登入後再按一次儲存即可）', 1002);
            $tok = $_POST['csrf'] ?? '';
            if (!is_string($tok) || $tok === '' || !hash_equals((string)($_SESSION['qc_csrf'] ?? ''), $tok)) {
                throw new Exception('連線憑證失效，請重新整理頁面後再試 (CSRF)', 1001);
            }
        }
        if (!$canView) throw new Exception('您沒有檢閱權限，請洽管理員於 品管檢驗 → 設定 → 權限設定 開通');

        // 目前的 CSRF token（唯讀，同源才讀得到；供前端「憑證失效自動換 token 重送一次」用，
        // 使用者才不必把填好的表單重打一遍）
        if ($act === 'csrf_token') {
            echo json_encode(['success' => true, 'csrf' => (string)$_SESSION['qc_csrf']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 清單 ----
        if ($act === 'list') {
            $kw = trim($_POST['keyword'] ?? '');
            // 客戶名稱一起帶出來（使用者要求：選了料號就要看得到是哪一家客戶的圖）
            $sql = "SELECT c.*, d.D_Setting_Id AS part_no, cu.customer AS customer_name,
                           pn.ProcessName AS from_process_name, u.user_cname AS created_by_name,
                           (SELECT COUNT(*) FROM qc_drawing_change_ack a WHERE a.change_id=c.id) AS ack_total,
                           (SELECT COUNT(*) FROM qc_drawing_change_ack a WHERE a.change_id=c.id AND a.acked_at IS NOT NULL) AS ack_done
                    FROM qc_drawing_change c
                    LEFT JOIN d_setting d ON d.d_id = c.d_id
                    LEFT JOIN customer_list cu ON cu.customer_id = d.Customer_Id
                    LEFT JOIN process_no pn ON pn.ProcessNo = c.from_process_no
                    LEFT JOIN user u ON u.id = c.created_by";
            $p = [];
            if ($kw !== '') { $sql .= " WHERE d.D_Setting_Id LIKE ? OR c.change_no LIKE ? OR c.summary LIKE ? OR cu.customer LIKE ?"; $p = ["%$kw%", "%$kw%", "%$kw%", "%$kw%"]; }
            $sql .= " ORDER BY c.id DESC LIMIT 200";
            $s = $pdo->prepare($sql); $s->execute($p);
            echo json_encode(['success' => true, 'rows' => $s->fetchAll(PDO::FETCH_ASSOC),
                              'can_manage' => $canManage, 'me' => (int)$uid, 'is_admin' => $isAdmin], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 單筆明細（含簽收名單與檢驗項目確認狀況）----
        if ($act === 'detail') {
            $id = (int)($_POST['id'] ?? 0);
            $s = $pdo->prepare("SELECT c.*, d.D_Setting_Id AS part_no, cu.customer AS customer_name,
                                       pn.ProcessName AS from_process_name, u.user_cname AS created_by_name
                                FROM qc_drawing_change c
                                LEFT JOIN d_setting d ON d.d_id=c.d_id
                                LEFT JOIN customer_list cu ON cu.customer_id = d.Customer_Id
                                LEFT JOIN process_no pn ON pn.ProcessNo=c.from_process_no
                                LEFT JOIN user u ON u.id=c.created_by
                                WHERE c.id=?");
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('查無此變更紀錄');
            $a = $pdo->prepare("SELECT k.*, u.user_cname FROM qc_drawing_change_ack k
                                LEFT JOIN user u ON u.id=k.user_id WHERE k.change_id=? ORDER BY k.id");
            $a->execute([$id]);
            $c = $pdo->prepare("SELECT * FROM qc_drawing_change_confirm WHERE change_id=? ORDER BY id");
            $c->execute([$id]);
            // 誰可以改這一筆：本人＝直接改／管理員改別人的＝要操作確認密碼／其餘不給改（使用者拍板）
            $perm = dwg_edit_permission($row, (int)$uid, $isAdmin);
            echo json_encode(['success' => true, 'row' => $row, 'acks' => $a->fetchAll(PDO::FETCH_ASSOC),
                              'confirms' => $c->fetchAll(PDO::FETCH_ASSOC),
                              'can_manage' => $canManage, 'me' => (int)$uid, 'is_admin' => $isAdmin,
                              'edit_perm' => $perm], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 建立 / 修改 ----
        if ($act === 'save_change') {
            if (!$canManage) throw new Exception('您沒有「管理檢驗設定」權限，無法建立圖面變更紀錄');
            $id      = (int)($_POST['id'] ?? 0);
            $d_id    = (int)($_POST['d_id'] ?? 0);
            $summary = trim($_POST['summary'] ?? '');
            $isDraft = (($_POST['keep_draft'] ?? '') === '1');   // 草稿可以先存著不送出
            if ($d_id <= 0)     throw new Exception('請選擇料號');
            if (!$isDraft && $summary === '') throw new Exception('請填寫變更摘要');
            $oldRev  = trim($_POST['old_revision'] ?? '');
            $newRev  = trim($_POST['new_revision'] ?? '');
            // 廠內版次＝發行章日期（見 ai-rules/15）。畫面自動帶新舊圖的發行日，使用者仍可改。
            $intOld  = trim($_POST['int_old_revision'] ?? '');
            $intNew  = trim($_POST['int_new_revision'] ?? '');
            $scope   = (($_POST['rev_scope'] ?? '') === 'internal') ? 'internal' : 'customer';
            foreach ([$intOld, $intNew] as $dv) {
                if ($dv !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dv)) throw new Exception('廠內版次要填發行日期（YYYY-MM-DD）');
            }
            // 僅廠內版次變更時不動客戶版次（使用者拍板：客戶沒改圖就不該動客戶版次）
            if ($scope === 'internal') { $oldRev = ''; $newRev = ''; }
            $fromP   = ($_POST['from_process_no'] ?? '') === '' ? null : (int)$_POST['from_process_no'];
            // 簽收對象可混合指定部門與個人；部門在這裡展開成人員（含子部門、只列在職）。
            // 全站預設簽收對象一律併回去——前端 chip 拿掉 × 只是體驗，真正的守門在這裡（鐵律8）。
            $defAck  = dwg_default_ack_get($pdo);
            $ackIds  = dwg_expand_ack_targets($pdo,
                         array_merge((array)($_POST['ack_users'] ?? []), $defAck['users']),
                         array_merge((array)($_POST['ack_depts'] ?? []), $defAck['depts']));

            // ── 新建：走共用 lib（與「料號附件上傳自動判定」產生的變更同一套邏輯）──
            // 建立單號、檢驗標準整組複製成新版次、簽收名單、通知都在 src/common/dwg_change_lib.php，
            // 兩個入口共用一份，避免日後只改到其中一邊。
            if (!$id) {
                $r = dwg_create_change($pdo, [
                    'd_id'            => $d_id,
                    'summary'         => $summary,
                    'old_revision'    => $oldRev,
                    'new_revision'    => $newRev,
                    'int_old_revision' => $intOld,
                    'int_new_revision' => $intNew,
                    'rev_scope'       => $scope,
                    'status'          => ($isDraft ? 'DRAFT' : 'OPEN'),
                    'change_date'     => ($_POST['change_date'] ?? ''),
                    'source'          => ($_POST['source'] ?? ''),
                    'customer_doc_no' => ($_POST['customer_doc_no'] ?? ''),
                    'from_process_no' => ($_POST['from_process_no'] ?? ''),
                    'detail'          => ($_POST['detail'] ?? ''),
                    'ack_users'       => $ackIds,   // 已展開，lib 內再展開一次也是同一個結果（冪等）
                    'created_by'      => (int)$uid,
                ]);
                echo json_encode(['success' => true, 'id' => $r['id'], 'status' => $r['status']], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // ── 修改既有紀錄：只改欄位與簽收名單，不再複製檢驗標準版次 ──
            $cn = $pdo->prepare("SELECT * FROM qc_drawing_change WHERE id=?");
            $cn->execute([$id]);
            $cur = $cn->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new Exception('查無此變更紀錄');
            $changeNo = (string)$cur['change_no'];   // 修改後若新增簽收人，通知要帶得出原單號

            // 修改權限（使用者拍板）：只能改自己填的；管理員可以改別人的，但要輸入操作確認密碼。
            // 前端也會擋，這裡是真正的守門（直打 API 繞不過去＝鐵律8）。
            $perm = dwg_edit_permission($cur, (int)$uid, $isAdmin);
            if (!$perm['can']) throw new Exception($perm['reason']);
            if ($perm['need_password']) {
                $pw = (string)($_POST['confirm_password'] ?? '');
                if ($pw === '') throw new Exception('請輸入操作確認密碼（' . $perm['reason'] . '）');
                $vr = eg_confirm_password_verify_scoped($pdo, (int)$uid, $pw, 'dwg_change_edit_other');
                if (empty($vr['ok'])) throw new Exception($vr['msg']);
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE qc_drawing_change SET d_id=?, old_revision=?, new_revision=?,
                           int_old_revision=?, int_new_revision=?, rev_scope=?, change_date=?,
                           source=?, customer_doc_no=?, from_process_no=?, summary=?, detail=?,
                           updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$d_id, $oldRev, $newRev, ($intOld ?: null), ($intNew ?: null), $scope,
                           ($_POST['change_date'] ?: null), trim($_POST['source'] ?? ''),
                           trim($_POST['customer_doc_no'] ?? ''), $fromP, $summary, trim($_POST['detail'] ?? ''),
                           (int)$uid, $id]);
            // 簽收名單（重設；已簽收者保留簽收時間）
            $exist = [];
            $e = $pdo->prepare("SELECT user_id, acked_at FROM qc_drawing_change_ack WHERE change_id=?");
            $e->execute([$id]);
            foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $r) $exist[(int)$r['user_id']] = $r['acked_at'];
            $pdo->prepare("DELETE FROM qc_drawing_change_ack WHERE change_id=?")->execute([$id]);
            $insA = $pdo->prepare("INSERT INTO qc_drawing_change_ack (change_id, user_id, acked_at) VALUES (?,?,?)");
            foreach ($ackIds as $u) { $insA->execute([$id, $u, ($exist[$u] ?? null)]); }
            $pdo->commit();

            // 通知尚未簽收的人（行動型：沒簽會一直留在未讀）。
            // 草稿階段不通知——草稿還沒送出，內容可能還是半成品。
            $newOnes = ($cur['status'] === 'DRAFT') ? []
                     : array_values(array_filter($ackIds, function ($u) use ($exist) { return empty($exist[$u]); }));
            if ($newOnes) {
                $partNo = dwg_part_info($pdo, $d_id)['part_no'];
                dwg_notify($pdo, $id, '【圖面變更】料號 ' . $partNo . '　請簽收確認',
                    dwg_notify_body(['change_no' => $changeNo, 'rev_scope' => $scope, 'old_revision' => $oldRev,
                                     'new_revision' => $newRev, 'int_old_revision' => $intOld,
                                     'int_new_revision' => $intNew, 'summary' => $summary]),
                    $newOnes, (int)$uid, 'reply');
            }
            echo json_encode(['success' => true, 'id' => $id, 'status' => $cur['status']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 草稿送出＝正式成立（這時才複製檢驗標準版次、回寫主檔版次、發簽收通知）----
        if ($act === 'submit_change') {
            if (!$canManage) throw new Exception('您沒有「管理檢驗設定」權限');
            $id = (int)($_POST['id'] ?? 0);
            $cn = $pdo->prepare("SELECT * FROM qc_drawing_change WHERE id=?");
            $cn->execute([$id]);
            $cur = $cn->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new Exception('查無此變更紀錄');
            $perm = dwg_edit_permission($cur, (int)$uid, $isAdmin);
            if (!$perm['can']) throw new Exception($perm['reason']);
            if ($perm['need_password']) {
                $pw = (string)($_POST['confirm_password'] ?? '');
                if ($pw === '') throw new Exception('請輸入操作確認密碼（' . $perm['reason'] . '）');
                $vr = eg_confirm_password_verify_scoped($pdo, (int)$uid, $pw, 'dwg_change_edit_other');
                if (empty($vr['ok'])) throw new Exception($vr['msg']);
            }
            $r = dwg_submit_change($pdo, $id, (int)$uid);
            echo json_encode(['success' => true] + $r, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 全站預設簽收對象（設定：僅管理員）----
        if ($act === 'save_default_ack') {
            if (!$isAdmin) throw new Exception('只有系統管理員可以設定預設簽收對象');
            dwg_default_ack_save($pdo, (array)($_POST['ack_users'] ?? []), (array)($_POST['ack_depts'] ?? []),
                                 dwg_user_name($pdo, (int)$uid));
            echo json_encode(['success' => true, 'default_ack' => dwg_default_ack_get($pdo)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 簽收 ----
        if ($act === 'ack_change') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT id FROM qc_drawing_change_ack WHERE change_id=? AND user_id=? LIMIT 1");
            $st->execute([$id, (int)$uid]);
            $rid = $st->fetchColumn();
            if (!$rid) throw new Exception('您不在此變更單的簽收名單內');
            $pdo->prepare("UPDATE qc_drawing_change_ack SET acked_at=NOW(), note=? WHERE id=?")
                ->execute([mb_substr(trim($_POST['note'] ?? ''), 0, 255), $rid]);
            dwg_notify_done($pdo, $id, (int)$uid);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 結案 / 刪除 ----
        if ($act === 'close_change') {
            if (!$canManage) throw new Exception('沒有權限');
            $pdo->prepare("UPDATE qc_drawing_change SET status=? WHERE id=?")
                ->execute([(($_POST['reopen'] ?? '') === '1' ? 'OPEN' : 'CLOSED'), (int)($_POST['id'] ?? 0)]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'delete_change') {
            if (!in_array('all', $feats, true)) throw new Exception('刪除變更紀錄僅限管理員');
            $id = (int)($_POST['id'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM qc_drawing_change_ack WHERE change_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM qc_drawing_change_confirm WHERE change_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM qc_drawing_change WHERE id=?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 下拉資料：料號搜尋 / 製程 / 人員 ----
        if ($act === 'part_search') {
            // 客戶名稱一起帶回來：使用者要求「料號綁定後要自動帶出客戶名稱」
            $s = $pdo->prepare("SELECT s.d_id, s.D_Setting_Id, s.Revision, s.Customer_Id, c.customer
                                  FROM d_setting s LEFT JOIN customer_list c ON c.customer_id = s.Customer_Id
                                 WHERE s.D_Setting_Id LIKE ? ORDER BY s.D_Setting_Id LIMIT 30");
            $s->execute(['%' . trim($_POST['keyword'] ?? '') . '%']);
            echo json_encode(['success' => true, 'rows' => $s->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---- 選定料號後的自動帶值：客戶名稱、目前客戶版次、廠內版次（新舊圖的發行日）----
        if ($act === 'part_meta') {
            $dId  = (int)($_POST['d_id'] ?? 0);
            if ($dId <= 0) throw new Exception('請選擇料號');
            $info = dwg_part_info($pdo, $dId);
            $pair = dwg_internal_rev_pair($pdo, $dId);
            echo json_encode(['success' => true, 'part' => $info, 'internal' => $pair], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($act === 'lookups') {
            $proc = $pdo->query("SELECT ProcessNo, ProcessName FROM process_no ORDER BY ProcessNo")->fetchAll(PDO::FETCH_ASSOC);
            // 人員與部門一律走共用函式（人員列表鐵則：不列離職／標記長期請假／依部門職稱排序），
            // 不要在這裡自己寫 user 表的 SQL——舊版就是那樣寫，會把離職者一起列出來。
            $lk = dwg_ack_lookup_data($pdo);
            echo json_encode(['success' => true, 'processes' => $proc,
                              'people' => $lk['people'], 'departments' => $lk['departments'],
                              'can_manage' => $canManage, 'is_admin' => $isAdmin, 'me' => (int)$uid,
                              'default_ack' => dwg_default_ack_get($pdo)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new Exception('未知的 action: ' . $act);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $e->getCode() === 1001 ? 'CSRF' : ($e->getCode() === 1002 ? 'LOGIN' : '');
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'code' => $code], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>圖面變更紀錄</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
    :root{ --ink:#4A3524; --ink2:#6B4423; --cream:#FCF7F0; --sand:#F7E0BD;
           --amber:#F0A24B; --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
    body { background:#F6F1EA; }
    .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px; margin-bottom:12px; }
    .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:#4A3524; font-weight:bold; }
    .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
    .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
    .as-tag { display:inline-block; background:var(--sand); border:1px solid var(--line); border-radius:4px;
              padding:2px 8px; font-size:12px; color:var(--ink); margin-left:8px; }
    .dc-row { cursor:pointer; }
    .dc-row:hover td { background:var(--cream); }
    .badge-open { background:var(--amber); color:#4A3524; }
    .badge-closed { background:#cfc3b2; color:#4A3524; }
    .ack-done { color:var(--amber-d); font-weight:bold; }
    .ack-wait { color:var(--coral); }
    .muted-help { color:#8a6a45; font-size:12px; }
    table.dc-table th { background:var(--cream); color:var(--ink); border-color:var(--line) !important; }
    table.dc-table td { border-color:var(--line) !important; vertical-align:middle; }
    .search-result-item { cursor:pointer; padding:6px 10px; border-bottom:1px solid var(--line); }
    .search-result-item:hover { background:var(--cream); }
    .search-result-item.active { background:var(--sand); }
    /* 料號即時搜尋的候選清單：浮在欄位下方，不把跳窗撐長 */
    .part-dd { position:absolute; z-index:1060; left:0; right:0; top:100%;
               background:#fff; border:1px solid var(--line); border-radius:0 0 6px 6px;
               max-height:210px; overflow:auto; box-shadow:0 6px 14px rgba(74,53,36,.18); }
    .err-msg { color:var(--coral); }
    .form-control.err { border-color:var(--coral); }
    .form-control.warn { border-color:var(--amber); }
    .userpick { max-height:190px; overflow:auto; border:1px solid var(--line); border-radius:6px; padding:6px; }
    .userpick label { font-weight:normal; display:inline-block; width:33%; margin:0; font-size:13px; }
    .rev-box { background:#FFFDF8; border:1px solid var(--line); border-radius:6px; padding:10px 10px 0; margin-bottom:10px; }
    .ro-auto { background:#F2ECE3 !important; color:#6B4423; }
    .badge-draft { background:#cfc3b2; color:#4A3524; }
    .page-help-btn { margin-left:8px; }
    .help-doc h4 { color:#6B4423; margin:14px 0 6px; font-size:15px; }
    .help-doc li { margin-bottom:4px; }
    @media print { .page-help-btn { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
    <div class="main_container">
        <?php include '../partPage/sideAndTopBarMenu.html'; ?>
        <div class="right_col" role="main">
            <div class="page-title">
                <div class="title_left"><h3 style="color:#4A3524;">圖面變更紀錄
                    <span class="as-tag">AS 表單編號 <?php echo htmlspecialchars($AS_DOC_NO, ENT_QUOTES, 'UTF-8'); ?>　圖面變更簽收單</span></h3></div>
                <div class="title_right"><div class="pull-right">
                    <button class="btn btn-warm btn-sm" id="btn-new"><i class="fa fa-plus"></i> 登錄圖面變更</button>
                    <button class="btn btn-warm-o btn-sm" id="btn-setting" style="display:none;"><i class="fa fa-cog"></i> 設定</button>
                    <button class="btn btn-default btn-sm page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
                </div></div>
            </div>
            <div class="clearfix"></div>

            <div class="warm-panel" style="font-size:13px;color:#6B4423;">
                <i class="fa fa-info-circle"></i>
                客戶改圖後即使<b>同料號同製程</b>，檢驗內容也可能整組不同。這裡登錄的每一筆變更會做三件事：
                ① 自動把該料號的<b>檢驗標準複製成新版次</b>（舊版保留，舊檢驗紀錄仍追溯得到當時標準）；
                ② <b>通知指定人員簽收</b>（未簽收會一直留在置頂未讀）；
                ③ 檢驗表 2.0 開啟<b>受影響製程</b>時跳出提醒，直到檢驗人員確認「已依新版次更新檢驗項目」。
                <br><span class="muted-help">檔案層的舊圖蓋作廢章／換上新圖，仍請到「圖面自動改檔名工具」的<b>作廢版</b>模式處理；這一頁負責的是管理紀錄與後續追蹤。</span>
            </div>

            <div class="warm-panel">
                <div class="input-group" style="max-width:420px;" data-eg-form data-eg-submit="#btn-search">
                    <input type="text" id="kw" class="form-control input-sm" placeholder="搜尋料號 / 變更單號 / 摘要（雙擊清空＝解除篩選）">
                    <span class="input-group-btn"><button class="btn btn-warm-o btn-sm" id="btn-search">搜尋</button></span>
                </div>
                <div class="table-responsive" style="margin-top:10px;">
                    <table class="table table-bordered table-condensed dc-table">
                        <thead><tr>
                            <th width="125">變更單號</th><th width="130">料號</th><th width="110">客戶</th>
                            <th width="105">客戶版次</th><th width="130">廠內版次(發行日)</th>
                            <th width="110">影響製程</th><th>變更摘要</th>
                            <th width="80">簽收</th><th width="80">狀態</th><th width="120">登錄</th>
                        </tr></thead>
                        <tbody id="dc-list"><tr><td colspan="10" class="text-center muted-help">載入中…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php include '../partPage/footer.html'; ?>
    </div>
</div>

<!-- 登錄/修改 -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-pencil-square-o"></i> 登錄圖面變更
                <small>AS <?php echo htmlspecialchars($AS_DOC_NO, ENT_QUOTES, 'UTF-8'); ?></small></h4>
        </div>
        <div class="modal-body" data-eg-form data-eg-submit="#btn-save">
            <input type="hidden" id="f-id">
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>料號（必填）</label>
                    <div style="position:relative;">
                        <input type="text" class="form-control input-sm" id="f-part-kw" autocomplete="off"
                               placeholder="輸入料號關鍵字即時搜尋，再點選（或 ↑↓＋Enter）">
                        <div id="part-results" class="part-dd" style="display:none;"></div>
                    </div>
                    <div id="part-picked" style="margin-top:4px;font-size:12px;"></div>
                </div>
                <div class="col-sm-6 form-group">
                    <label>客戶（依料號自動帶出，不可手填）</label>
                    <input type="text" class="form-control input-sm ro-auto" id="f-customer" readonly data-eg-skip
                           placeholder="選定料號後自動帶出">
                </div>
            </div>

            <!-- 版次：客戶版次與廠內版次是兩件事（使用者拍板 2026-08-21）
                 客戶版次＝客戶圖自己的版次（很多客戶圖沒有版次，所以不設必填）
                 廠內版次＝一律用「發行章日期」控制（見 ai-rules/15-圖面變更判定依據.md）
                 客戶改圖＝兩邊都要換版；廠內自行改圖＝只換廠內版次，不動客戶版次 -->
            <div class="rev-box">
                <div class="row">
                    <div class="col-sm-4 form-group">
                        <label>變更範圍（必填）</label>
                        <select class="form-control input-sm" id="f-scope">
                            <option value="customer">客戶版次變更（客戶＋廠內都換版）</option>
                            <option value="internal">僅廠內版次變更（客戶版次不動）</option>
                        </select>
                    </div>
                    <div class="col-sm-4 form-group cust-rev"><label>客戶　變更前版次</label>
                        <input type="text" class="form-control input-sm" id="f-oldrev" maxlength="30"></div>
                    <div class="col-sm-4 form-group cust-rev"><label>客戶　變更後版次</label>
                        <input type="text" class="form-control input-sm" id="f-newrev" maxlength="30"
                               placeholder="會成為檢驗標準的新版次名稱"></div>
                </div>
                <div class="row">
                    <div class="col-sm-4 form-group">
                        <label>廠內　變更前版次（舊圖發行日）</label>
                        <input type="date" class="form-control input-sm" id="f-intold">
                        <span class="muted-help" id="intold-src"></span>
                    </div>
                    <div class="col-sm-4 form-group">
                        <label>廠內　變更後版次（新圖發行日）</label>
                        <input type="date" class="form-control input-sm" id="f-intnew">
                        <span class="muted-help" id="intnew-src"></span>
                    </div>
                    <div class="col-sm-4">
                        <div class="muted-help" style="margin-top:22px;">
                            廠內版次一律用<b>發行章日期</b>控制。選定料號後會自動帶出此料號
                            <b>舊圖／新圖的發行日</b>；帶不出來代表這是首次發行，或那張圖還沒填發行章日期。
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-3 form-group"><label>變更日期</label><input type="date" class="form-control input-sm" id="f-date"></div>
                <div class="col-sm-3 form-group"><label>變更來源</label>
                    <select class="form-control input-sm" id="f-source"><option>客戶</option><option>內部</option></select></div>
                <div class="col-sm-3 form-group"><label>客戶文件編號</label><input type="text" class="form-control input-sm" id="f-cdoc"></div>
                <div class="col-sm-3 form-group"><label>從哪個製程開始受影響</label>
                    <select class="form-control input-sm" id="f-fromproc"></select>
                    <span class="muted-help">留白＝所有製程都提醒</span></div>
            </div>
            <div class="form-group"><label>變更摘要（必填，會出現在檢驗人員的提醒上）</label>
                <input type="text" class="form-control input-sm" id="f-summary" maxlength="200" placeholder="例：外徑由 Ø25±0.1 改為 Ø24.8±0.05，並新增同心度 0.02">
                <div id="f-summary-err" class="err-msg" style="font-size:11px;margin-top:3px;display:none;">請填寫變更摘要——只有你知道這次改了什麼</div></div>
            <div class="form-group"><label>變更內容明細</label>
                <textarea class="form-control" id="f-detail" rows="3"></textarea></div>
            <div class="form-group"><label>需簽收對象（可混合指定部門與個人；未簽收會一直顯示在置頂未讀）
                <span class="muted-help" id="def-ack-note"></span></label>
                <div id="f-ack-chips" style="display:flex;flex-wrap:wrap;gap:4px;padding:5px;background:#FCF7F0;
                     border:1px solid var(--line);border-radius:6px;min-height:32px;margin-bottom:4px;"></div>
                <div style="position:relative;">
                    <input type="text" id="f-ack-q" class="form-control input-sm" autocomplete="off"
                           placeholder="輸入姓名或部門名稱篩選，點選加入（選部門＝該部門含子部門的在職人員全收到）">
                    <div id="f-ack-dd" style="display:none;position:absolute;z-index:1060;left:0;right:0;top:100%;
                         background:#fff;border:1px solid var(--line);border-radius:0 0 6px 6px;max-height:210px;
                         overflow:auto;box-shadow:0 6px 14px rgba(74,53,36,.18);"></div>
                </div>
                <div id="f-ack-sum" class="muted-help" style="margin-top:4px;"></div>
            </div>
            <div class="alert" style="background:#FFF3E2;border:1px solid #E4D3BC;color:#6B4423;" id="ver-hint">
                <i class="fa fa-magic"></i> 建立後會自動把此料號目前的檢驗標準<b>整組複製成新版次</b>，舊版停用但保留；
                QC 只要到「檢驗標準管理」改動有變的尺寸即可。<b>修改既有紀錄時不會再複製一次。</b>
            </div>
        </div>
        <div class="modal-footer">
            <span class="muted-help pull-left" id="edit-perm-note" style="text-align:left;max-width:55%;"></span>
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-warm-o" id="btn-save-draft" style="display:none;">存成草稿（先不通知）</button>
            <button class="btn btn-warm" id="btn-save">儲存並通知簽收</button>
        </div>
    </div></div>
</div>

<!-- 明細 / 簽收 -->
<div class="modal fade" id="detailModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-file-text-o"></i> 圖面變更明細</h4>
        </div>
        <div class="modal-body" id="detail-body">載入中…</div>
        <div class="modal-footer">
            <button class="btn btn-default pull-left" id="btn-del" style="display:none;color:#DD5138;"><i class="fa fa-trash"></i> 刪除此紀錄</button>
            <button class="btn btn-default" id="btn-edit" style="display:none;"><i class="fa fa-pencil"></i> 修改</button>
            <button class="btn btn-warm" id="btn-submit" style="display:none;"><i class="fa fa-paper-plane"></i> 送出（正式成立並通知簽收）</button>
            <button class="btn btn-warm-o" id="btn-close-chg" style="display:none;"></button>
            <button class="btn btn-warm" id="btn-ack" style="display:none;"><i class="fa fa-check"></i> 我已確認並簽收</button>
            <button class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<!-- 設定：全站預設簽收對象（僅管理員）。開單者可以再加人，但移不掉這裡設定的對象。 -->
<div class="modal fade" id="settingModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-cog"></i> 圖面變更設定</h4>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>預設簽收（通知）對象</label>
                <div class="muted-help" style="margin-bottom:6px;">
                    這裡設定的部門與人員會<b>自動加進每一張圖面變更單</b>，開單者可以再加人，但<b>不能移除</b>。
                    設定只影響之後新建立的變更單，不會回頭改已經開好的單。
                </div>
                <div id="s-ack-chips" style="display:flex;flex-wrap:wrap;gap:4px;padding:5px;background:#FCF7F0;
                     border:1px solid var(--line);border-radius:6px;min-height:32px;margin-bottom:4px;"></div>
                <div style="position:relative;">
                    <input type="text" id="s-ack-q" class="form-control input-sm" autocomplete="off"
                           placeholder="輸入姓名或部門名稱篩選，點選加入（選部門＝該部門含子部門的在職人員全收到）">
                    <div id="s-ack-dd" style="display:none;position:absolute;z-index:1060;left:0;right:0;top:100%;
                         background:#fff;border:1px solid var(--line);border-radius:0 0 6px 6px;max-height:210px;
                         overflow:auto;box-shadow:0 6px 14px rgba(74,53,36,.18);"></div>
                </div>
                <div id="s-ack-sum" class="muted-help" style="margin-top:4px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-warm" id="btn-setting-save">儲存設定</button>
        </div>
    </div></div>
</div>

<!-- 操作確認密碼：管理員修改「別人填的」變更紀錄時要驗（走全站共用的操作確認密碼） -->
<div class="modal fade" id="pwModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-lock"></i> 操作確認密碼</h4>
        </div>
        <div class="modal-body">
            <div class="muted-help" id="pw-why" style="margin-bottom:8px;"></div>
            <input type="password" class="form-control input-sm" id="pw-input" autocomplete="off" placeholder="請輸入您的操作確認密碼">
            <div class="err-msg" id="pw-err" style="margin-top:5px;display:none;"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-warm" id="pw-ok">確認</button>
        </div>
    </div></div>
</div>

<!-- 使用說明（鐵律7：每頁必附） -->
<div class="modal fade" id="helpUseMask" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-book"></i> 圖面變更紀錄　使用說明</h4>
        </div>
        <div class="modal-body help-doc" style="max-height:70vh;overflow:auto;">
            <h4>這一頁在做什麼</h4>
            <p>客戶或我們自己改圖之後，就算<b>料號、製程都沒變</b>，檢驗內容也可能整組不同。這一頁登錄的每一筆變更會做三件事：
               ① 把該料號的<b>檢驗標準整組複製成新版次</b>（舊版停用但保留，舊檢驗紀錄仍追溯得到當時標準）；
               ② <b>通知指定人員簽收</b>（沒簽會一直留在置頂未讀）；
               ③ 檢驗表 2.0 開啟<b>受影響製程</b>時跳提醒，直到檢驗人員確認「已依新版次更新檢驗項目」。</p>

            <h4>兩種版次的差別（很重要）</h4>
            <ul>
                <li><b>客戶版次</b>：客戶圖面自己的版次（A、B、Rev.2…）。很多客戶圖根本沒有版次，所以不是必填。</li>
                <li><b>廠內版次</b>：我們自己出的圖，一律用<b>發行章日期</b>當版次（見圖面變更判定依據）。</li>
                <li><b>客戶版次變更</b>＝客戶改圖：客戶版次與廠內版次<b>兩邊都要換版</b>，存檔後新的客戶版次會<b>回寫料號主檔</b>。</li>
                <li><b>僅廠內版次變更</b>＝客戶沒改圖、我們自己重出圖：<b>只換廠內版次</b>，客戶版次欄位會反灰不給填，主檔版次也不會被動到。</li>
                <li>廠內版次的前／後日期會依這個料號的<b>舊圖與新圖發行章日期自動帶出</b>，帶不出來代表是首次發行、或那張圖還沒填發行章日期。</li>
            </ul>

            <h4>操作步驟</h4>
            <ol>
                <li>按右上「登錄圖面變更」。</li>
                <li>料號欄輸入關鍵字，<b>從清單點選</b>（必須綁到料號 ID，只打字不算）；選好後<b>客戶名稱會自動帶出</b>。</li>
                <li>選變更範圍（客戶版次／僅廠內版次），確認自動帶出的版次日期。</li>
                <li>填變更摘要（<b>必填</b>，這句話會出現在檢驗人員的提醒上）與明細。</li>
                <li>指定簽收對象 → 「儲存並通知簽收」。內容還沒想好可以先按「存成草稿」，草稿<b>不會通知任何人、也不會換檢驗標準版次</b>。</li>
            </ol>

            <h4>自動偵測換圖</h4>
            <ul>
                <li>在<b>料號附件</b>上傳圖面、或用<b>批圖編輯器</b>存成料號附件時，只要標籤屬於「自家出的圖」且發行章日期比前一版新，系統會判定成換圖並<b>問你要不要自動建立變更紀錄</b>。</li>
                <li>當下按「否」也沒關係：之後隨時可以到<b>料號主檔 → 料號附件</b>，按<b>「自動換圖記錄」</b>，系統會把料號、客戶、新舊發行日自動帶好建成草稿，並另開分頁讓你補完內容。</li>
            </ul>

            <h4>誰可以修改</h4>
            <ul>
                <li><b>自己填的</b>變更紀錄 → 直接修改。</li>
                <li><b>別人填的</b> → 只有系統管理員可以改，而且要輸入<b>操作確認密碼</b>。</li>
                <li>刪除變更紀錄僅限系統管理員。</li>
            </ul>

            <h4>設定入口</h4>
            <ul>
                <li>右上「<b>設定</b>」（僅管理員）：設定<b>預設簽收對象</b>——這些部門/人員會自動加進每一張變更單，開單者可以再加人但<b>移不掉</b>。</li>
                <li>哪些附件標籤算「自家出的圖」、哪個標籤代表「作廢」：在<b>料號附件的標籤設定</b>調整。</li>
            </ul>

            <h4>權限角色</h4>
            <ul>
                <li><b>檢閱</b>：有品管檢驗任一權限（唯讀檢閱／填寫檢驗／管理設定／修改歷史）即可看清單與明細、也能簽收。</li>
                <li><b>建立／修改</b>：需要「管理檢驗設定」權限。</li>
                <li><b>刪除</b>：僅系統管理員。</li>
            </ul>
        </div>
        <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>
    </div></div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_ack_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_ack_picker.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(function(){
    'use strict';
    var API = location.pathname;
    var CSRF = <?php echo json_encode($CSRF, JSON_UNESCAPED_SLASHES); ?>;
    $.ajaxPrefilter(function(o){
        if ((o.type||'GET').toUpperCase()!=='POST') return;
        if (o.data && typeof o.data==='object' && !(o.data instanceof FormData) && o.data.csrf===undefined) o.data.csrf=CSRF;
    });
    function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];}); }

    // 統一的送出：憑證失效（session 被 GC 掃掉、或 token 換過）時自動跟後端要一次目前的 token 再重送，
    // 使用者不必把填好的表單重打一遍。真的被登出（code=LOGIN）就照實說，不再誤報成 CSRF。
    function post(data, cb){
        return $.post(API, data, function(r){
            if (r && r.success === false && r.code === 'CSRF'){
                $.post(API, {action:'csrf_token'}, function(t){
                    if (t && t.success && t.csrf){ CSRF = t.csrf; data.csrf = t.csrf; $.post(API, data, cb, 'json'); }
                    else { cb(r); }
                }, 'json').fail(function(){ cb(r); });
                return;
            }
            cb(r);
        }, 'json');
    }

    var LOOK={processes:[],people:[],departments:[]}, CAN_MANAGE=false, pickedPart=null, curId=null, ME=0;
    var IS_ADMIN=false, DEF_ACK={users:[],depts:[]}, SET_ACK=null, curDetail=null, EDIT_PERM=null;
    // 由別頁（料號附件的「自動換圖記錄」）另開分頁帶進來的變更單 id，lookups 回來後自動開明細
    var OPEN_ID = parseInt((location.search.match(/[?&]id=(\d+)/)||[])[1]||0, 10);
    // 日期顯示一律 YYYY.MM.DD（ai-rules/20）；共用檔只匯出 egFmtDate()，各頁自己包一層是既有慣例
    function dispDate(v){ return (typeof egFmtDate==='function') ? egFmtDate(v) : String(v||'').substring(0,10); }
    var PENDING_ACK = null;   // lookups 尚未回來就開窗時，先記著等資料到了再套用
    var partTimer=null, partSeq=0, partRows=[], partActive=-1;   // 料號即時搜尋用

    // 簽收對象挑選器：共用檔 resource/js/eg_ack_picker.js（部門＋人員混合、已選反灰、
    // 人員被部門涵蓋也反灰）。料號附件上傳的變更登錄跳窗用的是同一支，不各頁自刻。
    var ACK = EGAckPicker.create({
        chips:'#f-ack-chips', input:'#f-ack-q', dropdown:'#f-ack-dd', summary:'#f-ack-sum'
    });
    post({action:'lookups'}, function(r){
        if(!r.success) return;
        LOOK=r; CAN_MANAGE=!!r.can_manage; IS_ADMIN=!!r.is_admin; ME=r.me||0;
        DEF_ACK = r.default_ack || {users:[],depts:[]};
        $('#btn-new').toggle(CAN_MANAGE);
        $('#btn-setting').toggle(IS_ADMIN);
        var nDef = (DEF_ACK.users||[]).length + (DEF_ACK.depts||[]).length;
        $('#def-ack-note').text(nDef ? '（帶鎖頭的是系統預設對象，不可移除；可再加人）' : '');
        $('#f-fromproc').html('<option value="">（全部製程都提醒）</option>'+
            (r.processes||[]).map(function(p){ return '<option value="'+p.ProcessNo+'">'+esc(p.ProcessName)+'</option>'; }).join(''));
        ACK.setData(r);
        if (PENDING_ACK) { ACK.setSelection(PENDING_ACK); PENDING_ACK = null; }   // lookups 還沒回來就開窗的情況
        ACK.setLocked(DEF_ACK);   // 預設對象一定在名單裡且移不掉（後端送出時還會再併一次）
        if (OPEN_ID) { openDetail(OPEN_ID); OPEN_ID = 0; }   // 由別頁另開分頁帶 ?id= 進來
    });

    function load(){
        $('#dc-list').html('<tr><td colspan="10" class="text-center muted-help">載入中…</td></tr>');
        post({action:'list', keyword:$('#kw').val()}, function(r){
            if(!r.success){ $('#dc-list').html('<tr><td colspan="10" class="text-danger">'+esc(r.message)+'</td></tr>'); return; }
            var rows=r.rows||[];
            $('#dc-list').html(rows.length ? rows.map(function(c){
                var ack=(c.ack_total>0) ? ((c.ack_done>=c.ack_total)
                        ? '<span class="ack-done">✔ '+c.ack_done+'/'+c.ack_total+'</span>'
                        : '<span class="ack-wait">'+c.ack_done+'/'+c.ack_total+'</span>') : '—';
                var st = c.status==='DRAFT' ? '<span class="badge badge-draft">草稿</span>'
                       : '<span class="badge '+(c.status==='CLOSED'?'badge-closed':'badge-open')+'">'+(c.status==='CLOSED'?'已結案':'進行中')+'</span>';
                var cust = (c.rev_scope==='internal')
                         ? '<span class="muted-help">僅廠內</span>'
                         : (esc(c.old_revision||'—')+' → <b>'+esc(c.new_revision||'—')+'</b>');
                return '<tr class="dc-row" data-id="'+c.id+'">'+
                    '<td><b>'+esc(c.change_no)+'</b></td>'+
                    '<td>'+esc(c.part_no||('d_id '+c.d_id))+'</td>'+
                    '<td>'+esc(c.customer_name||'—')+'</td>'+
                    '<td>'+cust+'</td>'+
                    '<td>'+(c.int_old_revision?dispDate(c.int_old_revision):'—')+' → <b>'+(c.int_new_revision?dispDate(c.int_new_revision):'—')+'</b></td>'+
                    '<td>'+(c.from_process_no ? esc(c.from_process_name||('#'+c.from_process_no))+' 起' : '全部製程')+'</td>'+
                    '<td>'+esc(c.summary||'<span class="muted-help">（草稿未填）</span>')+'</td>'+
                    '<td>'+(c.status==='DRAFT'?'<span class="muted-help">—</span>':ack)+'</td>'+
                    '<td>'+st+'</td>'+
                    '<td class="muted-help">'+esc(c.created_by_name||c.created_by||'')+'<br>'+String(c.created_at||'').substring(0,16)+'</td></tr>';
            }).join('') : '<tr><td colspan="10" class="text-center muted-help">尚無圖面變更紀錄</td></tr>');
        });
    }
    load();
    $('#btn-search').on('click', load);
    $('#kw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); load(); } });
    // 雙擊清空（eg_input_rules.js）後要同時解除篩選＝重新載入全部
    $('#kw').on('input', function(){ if($.trim(this.value)==='') load(); });

    // ---- 登錄 / 修改 ----
    // ---- 設定：全站預設簽收對象（僅管理員）----
    $('#btn-setting').on('click', function(){
        if(!SET_ACK){
            SET_ACK = EGAckPicker.create({chips:'#s-ack-chips', input:'#s-ack-q', dropdown:'#s-ack-dd', summary:'#s-ack-sum'});
        }
        SET_ACK.setData(LOOK);
        SET_ACK.setSelection(DEF_ACK);
        $('#settingModal').modal('show');
    });
    $('#btn-setting-save').on('click', function(){
        var sel = SET_ACK.getSelection();
        var $b = $(this).prop('disabled', true);
        post({action:'save_default_ack', ack_users:sel.users, ack_depts:sel.depts}, function(r){
            $b.prop('disabled', false);
            if(!r.success){ alert(r.message); return; }
            DEF_ACK = r.default_ack || {users:[],depts:[]};
            ACK.setLocked(DEF_ACK);
            var nDef = (DEF_ACK.users||[]).length + (DEF_ACK.depts||[]).length;
            $('#def-ack-note').text(nDef ? '（帶鎖頭的是系統預設對象，不可移除；可再加人）' : '');
            $('#settingModal').modal('hide');
            alert('已儲存預設簽收對象（只影響之後新建立的變更單）');
        });
    });

    // 使用說明（鐵律7：每頁必附）
    $('#btnPageHelp').on('click', function(){ $('#helpUseMask').modal('show'); });

    $('#btn-new').on('click', function(){ openEdit(null); });
    function openEdit(row, acks){
        $('#f-id').val(row?row.id:'');
        pickedPart = row ? {d_id:parseInt(row.d_id,10), part_no:String(row.part_no||''), customer:String(row.customer_name||'')} : null;
        $('#part-results').hide().empty(); partRows=[]; partActive=-1;
        $('#f-part-kw').val(pickedPart ? pickedPart.part_no : '');
        renderPicked();
        $('#f-oldrev').val(row?row.old_revision:''); $('#f-newrev').val(row?row.new_revision:'');
        $('#f-customer').val(row?(row.customer_name||''):'');
        $('#f-scope').val(row?(row.rev_scope||'customer'):'customer');
        $('#f-intold').val(row&&row.int_old_revision?String(row.int_old_revision).substring(0,10):'');
        $('#f-intnew').val(row&&row.int_new_revision?String(row.int_new_revision).substring(0,10):'');
        $('#intold-src').text(''); $('#intnew-src').text('');
        applyScope();
        // 修改別人填的紀錄要操作確認密碼（後端也會再擋一次）
        EDIT_PERM = row ? (curDetail && curDetail.edit_perm) || null : null;
        $('#edit-perm-note').html(EDIT_PERM && EDIT_PERM.need_password
            ? '<i class="fa fa-lock"></i> '+esc(EDIT_PERM.reason) : '');
        // 草稿才給「存成草稿」；已送出的單不能退回草稿
        $('#btn-save-draft').toggle(!row || row.status==='DRAFT');
        $('#btn-save').text(row && row.status==='DRAFT' ? '送出（正式成立並通知簽收）' : '儲存並通知簽收');
        $('#f-date').val(row&&row.change_date?String(row.change_date).substring(0,10):new Date().toISOString().substring(0,10));
        $('#f-source').val(row?(row.source||'客戶'):'客戶');
        $('#f-cdoc').val(row?row.customer_doc_no:''); $('#f-fromproc').val(row&&row.from_process_no?row.from_process_no:'');
        $('#f-summary').val(row?row.summary:''); $('#f-detail').val(row?row.detail:'');
        // 修改既有紀錄時要把原本的簽收名單帶回來——不帶回來的話，存檔會把名單整個洗掉
        //（存檔是「重設名單」，送什麼就是什麼）。簽收表只存 user_id，所以部門選擇無法還原，
        // 但那些人本來就已經被展開成個人存進名單了，不影響結果。
        var ackIdsNow = (acks||[]).map(function(a){ return parseInt(a.user_id,10); }).filter(Boolean);
        ACK.clear();
        if (LOOK.people && LOOK.people.length) {
            ACK.setSelection({users:ackIdsNow, depts:[]});
            ACK.setLocked(DEF_ACK);
        } else PENDING_ACK = {users:ackIdsNow, depts:[]};
        // 新建時料號還沒選＝先把自動帶值清乾淨（推導欄位鐵則：算不出來就清空，不留舊值）
        if (!row) { $('#f-customer').val(''); $('#f-intold,#f-intnew').val(''); }
        $('#ver-hint').toggle(!row);
        $('#detailModal').modal('hide');
        $('#editModal').modal('show');
    }
    // 變更範圍切換：僅廠內版次時，客戶版次欄位反灰不給填（後端也會忽略送上來的值）
    function applyScope(){
        var internal = $('#f-scope').val()==='internal';
        $('.cust-rev input').prop('readonly', internal).toggleClass('ro-auto', internal);
        if (internal) $('#f-oldrev,#f-newrev').val('');
    }
    $('#f-scope').on('change', applyScope);

    // ---- 料號即時搜尋（必須綁到 d_id，不接受純打字）----------------------------
    // 只存 d_id：料號字串會因改版／重新命名而變，綁 ID 才不會斷線。
    // 對應地，master_data_management.php 的料號刪除也已把 qc_drawing_change 列入
    // 關聯阻擋＋綁定移轉，避免料號被刪掉後這裡的紀錄變孤兒。
    function renderPicked(){
        var $p = $('#part-picked'), $kw = $('#f-part-kw');
        $kw.removeClass('err warn');
        if(!pickedPart){
            $p.html('<span class="err-msg"><i class="fa fa-exclamation-circle"></i> 尚未選定料號——請在上面輸入關鍵字，再從清單點選（必須綁定料號 ID，只打字不算）</span>');
            $kw.addClass('err');
            return;
        }
        var kw = $.trim($kw.val());
        var stale = (kw !== '' && kw !== pickedPart.part_no);
        $p.html('<span style="color:#C77C1A;"><i class="fa fa-check"></i> 已綁定料號 <b>'+esc(pickedPart.part_no)+'</b></span>'+
                ' <span class="muted-help">(d_id '+pickedPart.d_id+')</span>'+
                (pickedPart.customer ? ' <span class="muted-help">｜客戶 '+esc(pickedPart.customer)+'</span>' : '')+
                (stale ? '<br><span class="err-msg">搜尋字串已改成「'+esc(kw)+'」但還沒重新點選，現在存檔仍會綁上面那一筆</span>' : ''));
        if(stale) $kw.addClass('warn');
    }
    function renderPartResults(){
        $('#part-results').show().html(partRows.length ? partRows.map(function(p,i){
            return '<div class="search-result-item part-pick'+(i===partActive?' active':'')+'" data-i="'+i+'">'+
                   esc(p.D_Setting_Id)+(p.Revision?' <span class="muted-help">Rev '+esc(p.Revision)+'</span>':'')+
                   (p.customer?' <span class="muted-help">｜'+esc(p.customer)+'</span>':'')+
                   ' <span class="muted-help">d_id '+p.d_id+'</span></div>';
        }).join('') : '<div class="search-result-item muted-help">查無此料號</div>');
    }
    function partSearch(){
        var kw = $.trim($('#f-part-kw').val());
        if(kw===''){ $('#part-results').hide().empty(); partRows=[]; partActive=-1; return; }
        var seq = ++partSeq;
        $('#part-results').show().html('<div class="search-result-item muted-help">搜尋中…</div>');
        post({action:'part_search', keyword:kw}, function(r){
            if(seq!==partSeq) return;        // 打字快時舊請求可能晚回來，丟棄免蓋掉新結果
            if(!r.success){ $('#part-results').html('<div class="search-result-item text-danger">'+esc(r.message)+'</div>'); return; }
            partRows = r.rows||[]; partActive = partRows.length ? 0 : -1;
            renderPartResults();
        });
    }
    function pickPart(i){
        var p = partRows[i]; if(!p) return;
        pickedPart = { d_id:parseInt(p.d_id,10), part_no:String(p.D_Setting_Id), customer:String(p.customer||'') };
        $('#f-part-kw').val(pickedPart.part_no);
        $('#f-customer').val(pickedPart.customer);
        if(!$('#f-oldrev').val()) $('#f-oldrev').val(p.Revision||'');
        $('#part-results').hide(); partRows=[]; partActive=-1;
        renderPicked();
        loadPartMeta(pickedPart.d_id);
    }
    /**
     * 選定料號後自動帶值（使用者要求）：客戶名稱、目前客戶版次、廠內版次的新舊發行日。
     * 廠內版次帶的是「這個料號目前最新一版的發行日」與「再往前一版的發行日」，
     * 範圍與系統判定換圖時用的完全一致（同料號含 sibling、自家圖標籤、排除作廢）。
     */
    function loadPartMeta(dId){
        $('#intold-src,#intnew-src').text('查詢中…');
        post({action:'part_meta', d_id:dId}, function(r){
            if(!r.success){ $('#intold-src,#intnew-src').text(''); return; }
            if (pickedPart && parseInt(pickedPart.d_id,10)===parseInt(dId,10)) {
                pickedPart.customer = (r.part && r.part.customer) || '';
                $('#f-customer').val(pickedPart.customer);
                renderPicked();
            }
            var iv = r.internal || {};
            // 只在使用者還沒自己填的時候才自動帶（不要蓋掉手動輸入的值）
            if (iv.new_date && !$('#f-intnew').val()) $('#f-intnew').val(iv.new_date);
            if (iv.old_date && !$('#f-intold').val()) $('#f-intold').val(iv.old_date);
            $('#intnew-src').text(iv.new_date ? ('自動帶入最新圖：'+(iv.new_name||'')) : '此料號還沒有帶發行章日期的自家圖');
            $('#intold-src').text(iv.old_date ? ('自動帶入前一版：'+(iv.old_name||'')) : '沒有更舊的版本＝首次發行');
            if (r.part && r.part.revision && !$('#f-oldrev').val()) $('#f-oldrev').val(r.part.revision);
        });
    }
    $('#f-part-kw').on('input', function(){
        // 雙擊清空（eg_input_rules.js）會走到這裡：欄位清空＝連同已綁定的料號一起解除
        if($.trim(this.value)==='') pickedPart = null;
        clearTimeout(partTimer);
        partTimer = setTimeout(partSearch, 220);
        renderPicked();
    });
    $('#f-part-kw').on('focus', function(){ if(partRows.length) $('#part-results').show(); });
    $('#f-part-kw').on('keydown', function(e){
        if(e.key==='ArrowDown' || e.key==='ArrowUp'){
            if(!partRows.length) return;
            e.preventDefault();
            partActive = (partActive + (e.key==='ArrowDown'?1:-1) + partRows.length) % partRows.length;
            renderPartResults();
            var a = $('#part-results .active')[0];
            if(a && a.scrollIntoView) a.scrollIntoView({block:'nearest'});
        } else if(e.key==='Enter'){
            // 有候選就選它；沒有候選則不攔，交給 eg_input_rules.js 跳下一欄
            if(partActive>=0 && partRows.length){ e.preventDefault(); pickPart(partActive); }
        } else if(e.key==='Escape'){
            $('#part-results').hide();
        }
    });
    $(document).on('click','.part-pick', function(){ pickPart(parseInt($(this).attr('data-i'),10)); });
    $(document).on('click', function(e){
        if(!$(e.target).closest('#f-part-kw,#part-results').length) $('#part-results').hide();
    });
    /** 存檔（keepDraft=true＝先存草稿不通知）。改別人填的紀錄要先過操作確認密碼。 */
    function doSave(keepDraft, confirmPw){
        if(!pickedPart){ renderPicked(); $('#f-part-kw').focus(); alert('請從即時搜尋清單中點選料號（需綁定料號 ID）'); return; }
        var summary = $('#f-summary').val().trim();
        $('#f-summary').removeClass('err'); $('#f-summary-err').hide();
        if(!keepDraft && !summary){
            $('#f-summary').addClass('err').focus();
            alert('請填寫變更摘要——只有你知道這次改了什麼，這句話會出現在檢驗人員的提醒上');
            return;
        }
        var ackSel=ACK.getSelection();
        var $b=$('#btn-save,#btn-save-draft').prop('disabled',true);
        post({ action:'save_change', id:$('#f-id').val(), d_id:pickedPart.d_id,
            rev_scope:$('#f-scope').val(),
            old_revision:$('#f-oldrev').val(), new_revision:$('#f-newrev').val(),
            int_old_revision:$('#f-intold').val(), int_new_revision:$('#f-intnew').val(),
            change_date:$('#f-date').val(),
            source:$('#f-source').val(), customer_doc_no:$('#f-cdoc').val(), from_process_no:$('#f-fromproc').val(),
            summary:summary, detail:$('#f-detail').val(),
            keep_draft:(keepDraft?'1':'0'), confirm_password:(confirmPw||''),
            ack_users:ackSel.users, ack_depts:ackSel.depts
        }, function(r){
            $b.prop('disabled',false);
            if(!r.success){ alert('儲存失敗：'+r.message); return; }
            // 既有的草稿按「儲存並通知簽收」＝存完再送出，讓它正式成立
            if(!keepDraft && r.status==='DRAFT' && r.id){
                post({action:'submit_change', id:r.id, confirm_password:(confirmPw||'')}, function(s2){
                    $('#editModal').modal('hide'); load();
                    alert(s2.success ? ('已送出'+(s2.notified?('，並已通知 '+s2.notified+' 位人員簽收'):'')) : ('送出失敗：'+s2.message));
                });
                return;
            }
            $('#editModal').modal('hide'); load();
            var n=ACK.count();
            alert(keepDraft ? '已存成草稿（尚未通知任何人，也還沒換檢驗標準版次）'
                            : ('已儲存'+(n?('，並已通知 '+n+' 位人員簽收'):'')));
        }).fail(function(x){ $b.prop('disabled',false); alert('儲存錯誤：'+x.responseText); });
    }
    $('#btn-save').on('click', function(){
        if(EDIT_PERM && EDIT_PERM.need_password){ askPassword(EDIT_PERM.reason, function(pw){ doSave(false, pw); }); return; }
        doSave(false, '');
    });
    $('#btn-save-draft').on('click', function(){
        if(EDIT_PERM && EDIT_PERM.need_password){ askPassword(EDIT_PERM.reason, function(pw){ doSave(true, pw); }); return; }
        doSave(true, '');
    });

    // ---- 操作確認密碼（管理員修改他人填的紀錄時）----
    var PW_CB = null;
    function askPassword(why, cb){
        PW_CB = cb;
        $('#pw-why').text(why||'此操作需要輸入操作確認密碼');
        $('#pw-input').val(''); $('#pw-err').hide();
        $('#pwModal').modal('show');
        setTimeout(function(){ $('#pw-input').focus(); }, 350);
    }
    $('#pw-ok').on('click', function(){
        var pw = $('#pw-input').val();
        if(!pw){ $('#pw-err').text('請輸入操作確認密碼').show(); return; }
        $('#pwModal').modal('hide');
        if(PW_CB){ var cb=PW_CB; PW_CB=null; cb(pw); }
    });
    $('#pw-input').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); $('#pw-ok').click(); } });

    // ---- 明細 / 簽收 ----
    $('#dc-list').on('click','.dc-row', function(){ openDetail($(this).data('id')); });
    function openDetail(id){
        curId=id;
        $('#detail-body').html('載入中…'); $('#btn-ack,#btn-edit,#btn-close-chg,#btn-del,#btn-submit').hide();
        $('#detailModal').modal('show');
        post({action:'detail', id:id}, function(r){
            if(!r.success){ $('#detail-body').html('<div class="text-danger">'+esc(r.message)+'</div>'); return; }
            var c=r.row, acks=r.acks||[], cfs=r.confirms||[]; ME=r.me; curDetail=r;
            var myAck=null; acks.forEach(function(a){ if(parseInt(a.user_id)===parseInt(ME)) myAck=a; });
            var h='<table class="table table-condensed table-bordered dc-table">'+
                '<tr><th width="120">變更單號</th><td><b>'+esc(c.change_no)+'</b>　<span class="as-tag">AS '+esc(c.as_doc_no)+'</span></td></tr>'+
                (c.status==='DRAFT'?'<tr><th>狀態</th><td><span class="badge badge-draft">草稿</span> <span class="muted-help">尚未送出：還沒通知任何人，也還沒換檢驗標準版次。補完內容後按下方「送出」才正式成立。</span></td></tr>':'')+
                '<tr><th>料號</th><td>'+esc(c.part_no||'')+(c.customer_name?('　<span class="muted-help">客戶：'+esc(c.customer_name)+'</span>'):'')+'</td></tr>'+
                '<tr><th>變更範圍</th><td>'+(c.rev_scope==='internal'?'僅廠內版次變更（客戶版次不動）':'客戶版次變更（客戶＋廠內都換版）')+'</td></tr>'+
                (c.rev_scope==='internal'?'':
                    ('<tr><th>客戶版次</th><td>'+esc(c.old_revision||'—')+' → <b>'+esc(c.new_revision||'—')+'</b></td></tr>'))+
                '<tr><th>廠內版次<br><span class="muted-help">(發行日)</span></th><td>'+
                    (c.int_old_revision?dispDate(c.int_old_revision):'（首次發行）')+' → <b>'+
                    (c.int_new_revision?dispDate(c.int_new_revision):'—')+'</b>'+
                    (c.new_version_id?('　<span class="muted-help">已建立檢驗標準新版本 #'+c.new_version_id+'（舊版 #'+(c.old_version_id||'—')+' 已停用保留）</span>'):'')+'</td></tr>'+
                '<tr><th>影響製程</th><td>'+(c.from_process_no?('<b>'+esc(c.from_process_name||'')+'</b> 起（含）之後的製程'):'<b>所有製程</b>')+'</td></tr>'+
                '<tr><th>變更來源</th><td>'+esc(c.source||'')+(c.customer_doc_no?('　文件編號 '+esc(c.customer_doc_no)):'')+'　'+String(c.change_date||'').substring(0,10)+'</td></tr>'+
                '<tr><th>摘要</th><td>'+(c.summary?esc(c.summary):'<span class="err-msg">（草稿尚未填寫）</span>')+'</td></tr>'+
                (c.detail?('<tr><th>明細</th><td style="white-space:pre-wrap">'+esc(c.detail)+'</td></tr>'):'')+
                '</table>';
            h+='<b>簽收狀況</b><table class="table table-condensed table-bordered dc-table"><thead><tr><th>人員</th><th width="170">簽收時間</th><th>備註</th></tr></thead><tbody>'+
               (acks.length ? acks.map(function(a){
                   return '<tr><td>'+esc(a.user_cname||('#'+a.user_id))+'</td><td>'+
                          (a.acked_at?('<span class="ack-done">✔ '+String(a.acked_at).substring(0,16)+'</span>'):'<span class="ack-wait">尚未簽收</span>')+
                          '</td><td>'+esc(a.note||'')+'</td></tr>';
               }).join('') : '<tr><td colspan="3" class="muted-help">未指定簽收人員</td></tr>')+'</tbody></table>';
            h+='<b>檢驗項目更新確認</b>（由檢驗人員在檢驗表 2.0 上確認）'+
               '<table class="table table-condensed table-bordered dc-table"><thead><tr><th>製程</th><th width="170">確認時間</th><th>確認人</th><th>備註</th></tr></thead><tbody>'+
               (cfs.length ? cfs.map(function(f){
                   return '<tr><td>'+esc(f.process_name||'—')+'</td><td>'+String(f.confirmed_at||'').substring(0,16)+'</td><td>'+esc(f.confirmed_by||'')+'</td><td>'+esc(f.note||'')+'</td></tr>';
               }).join('') : '<tr><td colspan="4" class="muted-help">尚無製程確認已更新檢驗項目</td></tr>')+'</tbody></table>';
            $('#detail-body').html(h);
            $('#btn-submit').hide();
            if(myAck && !myAck.acked_at && c.status!=='DRAFT') $('#btn-ack').show();
            var perm = r.edit_perm || {can:false};
            if(r.can_manage && perm.can){
                $('#btn-edit').show().off('click').on('click', function(){ openEdit(c, acks); });
                if(c.status==='DRAFT'){
                    // 草稿：送出才正式成立（複製檢驗標準版次＋回寫主檔版次＋發簽收通知）
                    $('#btn-submit').show().off('click').on('click', function(){
                        if(!String(c.summary||'').trim()){ alert('請先按「修改」把變更摘要填好再送出'); return; }
                        if(!confirm('送出後會：\n① 把此料號的檢驗標準整組複製成新版次\n② 回寫料號主檔版次\n③ 通知簽收對象\n\n確定送出？')) return;
                        var go = function(pw){
                            post({action:'submit_change', id:c.id, confirm_password:(pw||'')}, function(res){
                                if(!res.success){ alert(res.message); return; }
                                alert('已送出'+(res.notified?('，並已通知 '+res.notified+' 位人員簽收'):''));
                                $('#detailModal').modal('hide'); load();
                            });
                        };
                        if(perm.need_password) askPassword(perm.reason, go); else go('');
                    });
                }
            } else if (r.can_manage) {
                $('#btn-edit').hide();
            }
            if(r.can_manage && c.status!=='DRAFT'){
                $('#btn-close-chg').show().text(c.status==='CLOSED'?'重新開啟':'標記為已結案').off('click').on('click', function(){
                    post({action:'close_change', id:c.id, reopen:(c.status==='CLOSED'?'1':'0')}, function(){ $('#detailModal').modal('hide'); load(); });
                });
            }
            $('#btn-del').toggle(!!r.can_manage).off('click').on('click', function(){
                if(!confirm('確定刪除變更紀錄 '+c.change_no+'？（不會還原已建立的檢驗標準新版本）')) return;
                post({action:'delete_change', id:c.id}, function(res){
                    if(!res.success){ alert(res.message); return; }
                    $('#detailModal').modal('hide'); load();
                });
            });
        });
    }
    $('#btn-ack').on('click', function(){
        var note=prompt('簽收備註（選填）：','');
        if(note===null) return;
        post({action:'ack_change', id:curId, note:note}, function(r){
            if(!r.success){ alert(r.message); return; }
            alert('已簽收'); openDetail(curId); load();
        });
    });

    // 由通知點入：?ack=<id> 直接開明細
    var qs=new URLSearchParams(location.search);
    if(qs.get('ack')) setTimeout(function(){ openDetail(parseInt(qs.get('ack'))); }, 400);
});
</script>
</body>
</html>
