<?php
session_start();

if (!isset($_SESSION['userName']) && !isset($_SESSION['id'])) { // 未登入則返回登入頁
    $_SESSION['lastpage'] = "../../views/liveEvent/createEvent.php";
    header("Location:../../index.php");
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$userName    = $_SESSION['user_cname'];
@$id          = $_SESSION['id'];
@$user_status = $_SESSION['status'];

$conn = new DBConnection();

// === 角色權限（RBAC，與報價單同一套；角色指派於「管理設定 → 使用者權限」頁）===
require_once '../../src/common/rbac.php';
$_features  = rbac_user_features($conn->getPDO(), (int)$id);
$CAN_VIEW   = rbac_has($_features, 'notice_view');
$CAN_CREATE = rbac_has($_features, 'notice_create');
$CAN_EDIT   = rbac_has($_features, 'notice_edit');
$CAN_DELETE = rbac_has($_features, 'notice_delete');
$IS_ADMIN   = rbac_has($_features, 'all');
$can_manage = $CAN_EDIT || $CAN_DELETE; // 列表「已讀/操作」欄是否顯示

// 無檢視權限（且非初始全權狀態）→ 導回儀表板
// 注意：此處使用者「已登入」，不可設定 lastpage 再導回登入頁，
// 否則登入成功後又被導回本頁→再踢回登入頁，形成無限循環（帳密正確也永遠登不進去）
if (!$CAN_VIEW) {
    header("Location:../../views/admin/dashboard.php");
    exit();
}

// 本頁功能清單（供「權限設定」勾選使用）
$PAGE_FEATURES = [
    ['code' => 'notice_view',       'label' => '檢視公告/通知'],
    ['code' => 'notice_create',     'label' => '新增公告/通知'],
    ['code' => 'notice_edit',       'label' => '編輯公告/通知'],
    ['code' => 'notice_delete',     'label' => '刪除公告/通知'],
    ['code' => 'notice_tag_manage', 'label' => '附件標籤管理（主管：新增/停用標籤、4G/Telegram/浮水印開關、預設標籤）'],
];

// 目前使用者的公告角色（標題列顯示用）＋ 是否為初始全權(bootstrap)
$my_notice_roles = [];
$is_bootstrap = false;
try {
    $rbac_pdo = $conn->getPDO();
    $st = $rbac_pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                              WHERE ur.user_id = ? AND (r.module = 'notice' OR r.is_system = 1)
                              ORDER BY r.is_system DESC, r.role_id ASC");
    $st->execute([(int)$id]);
    $my_notice_roles = $st->fetchAll(PDO::FETCH_COLUMN);
    // 系統尚無任何管理員 → bootstrap（rbac 會給全權）
    $anyAdmin = (int)$rbac_pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id WHERE rf.feature_code = 'all'")->fetchColumn();
    $is_bootstrap = ($anyAdmin === 0);
} catch (Exception $e) {}
// 只有「尚未被指派任何角色」的人，才算是初始全權管理者（與 rbac.php 一致）
$effective_bootstrap_admin = $is_bootstrap && empty($my_notice_roles);

// 所有公告角色 + 其功能（? 權限說明跳窗用）
$all_notice_roles = [];
try {
    foreach ($rbac_pdo->query("SELECT role_id, role_name, is_system FROM roles WHERE module = 'notice' OR is_system = 1 ORDER BY is_system DESC, role_id ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rf = $rbac_pdo->prepare("SELECT feature_code FROM role_features WHERE role_id = ?");
        $rf->execute([$r['role_id']]);
        $r['features'] = $rf->fetchAll(PDO::FETCH_COLUMN);
        $all_notice_roles[] = $r;
    }
} catch (Exception $e) {}

// 部門（含階層）
$departments = $conn->getAll("SELECT id, name, parent_id, level FROM department ORDER BY level ASC, sort_order ASC, name ASC");
$deptMap = [];
foreach ($departments as $d) { $deptMap[$d['id']] = $d; }
if (!function_exists('getDeptPath')) {
    function getDeptPath($deptId, $deptMap) {
        if (empty($deptId) || !isset($deptMap[$deptId])) return '未指定';
        $path = []; $curr = $deptMap[$deptId]; $limit = 10;
        while ($curr && $limit-- > 0) {
            array_unshift($path, $curr['name']);
            if ($curr['level'] <= 3) break;
            $parentId = $curr['parent_id'];
            if (!$parentId || !isset($deptMap[$parentId])) break;
            $curr = $deptMap[$parentId];
        }
        return implode(' / ', $path);
    }
}

// 身分
$statuses = $conn->getAll("SELECT id, title FROM `user_status` ORDER BY id");

// 人員（含主要部門/職稱）
$users = $conn->getAll(
    "SELECT u.id, u.user_cname, d.name AS department_name, d.id AS department_id, p.name AS position_name
     FROM user u
     LEFT JOIN user_department_position_map udpm ON u.id = udpm.user_id AND udpm.is_main = 1
     LEFT JOIN department d ON udpm.department_id = d.id
     LEFT JOIN position p ON udpm.position_id = p.id
     WHERE u.state NOT IN (0, 90) ORDER BY u.user_cname ASC"
);

// 名稱對應表（列表顯示用）
$dept_name_map = []; foreach ($departments as $d) { $dept_name_map[$d['id']] = $d['name']; }
$status_name_map = []; foreach ($statuses as $s) { $status_name_map[$s['id']] = $s['title']; }
$user_name_map = []; foreach ($users as $u) { $user_name_map[$u['id']] = $u['user_cname']; }

// 各筆對象
$targets_by_event = [];
foreach ($conn->getAll("SELECT * FROM live_event_target") as $t) {
    $targets_by_event[$t['live_event_id']][] = $t;
}
if (!function_exists('eg_target_pills')) {
    function eg_target_pills($eventId, $targets_by_event, $dn, $sn, $un) {
        $list = $targets_by_event[$eventId] ?? [];
        if (empty($list)) return '<span class="eg-pill">—</span>';
        $html = '';
        foreach ($list as $t) {
            switch ($t['target_type']) {
                case 'all':    $html .= '<span class="eg-pill eg-pill-all">全體</span>'; break;
                case 'dept':   $html .= '<span class="eg-pill eg-pill-dept">' . htmlspecialchars($dn[$t['target_id']] ?? $t['target_id']) . '</span>'; break;
                case 'status': $html .= '<span class="eg-pill eg-pill-status">' . htmlspecialchars($sn[$t['target_id']] ?? $t['target_id']) . '</span>'; break;
                case 'user':   $html .= '<span class="eg-pill eg-pill-user"><i class="fa fa-user"></i> ' . htmlspecialchars($un[$t['target_id']] ?? $t['target_id']) . '</span>'; break;
            }
        }
        return $html;
    }
}

// 公告通知列表（含建立者名稱）
$events = $conn->getAll("SELECT le.*, u.user_cname AS creator_name
                         FROM `live_event` le
                         LEFT JOIN `user` u ON u.id = le.created_by
                         ORDER BY le.eventdate DESC, le.id DESC");

// 各筆已讀人數
$read_counts = [];
foreach ($conn->getAll("SELECT live_event_id, COUNT(*) AS cnt FROM `live_event_for_user` WHERE oready_read = 1 GROUP BY live_event_id") as $rc) {
    $read_counts[$rc['live_event_id']] = $rc['cnt'];
}

// 編輯中暫存值
@$eventid     = $_SESSION['eventid'];
@$title       = $_SESSION['title'];
@$content     = $_SESSION['content'];
@$eventdate   = $_SESSION['eventdate'];
@$enddate     = $_SESSION['enddate'];
$is_edit      = isset($_SESSION['eventid']) && $_SESSION['eventid'] != "";

// 編輯時，已選對象（含各對象的通知模式）
$selected_targets = [];
$selected_target_modes = []; // code => read/sign/reply
$reply_deadline = '';
$show_status = 0;
if ($is_edit) {
    foreach (($targets_by_event[$eventid] ?? []) as $t) {
        $code = ($t['target_type'] === 'all') ? 'all' : ($t['target_type'] . '-' . $t['target_id']);
        $selected_targets[] = $code;
        $selected_target_modes[$code] = $t['mode'] ?? 'read';
    }
    $ev = $conn->getOne("SELECT * FROM live_event WHERE id = " . (int)$eventid);
    if ($ev) {
        $reply_deadline = $ev['reply_deadline'] ?? '';
        $show_status = (int)($ev['show_status_to_others'] ?? 0);
    }
}
// Telegram 輪詢心跳（規格 6-4）：poll_heartbeat.txt 由輪詢本體每輪更新；超過 5 分鐘未更新＝服務異常
$tg_hb_stale = false;
try {
    require_once '../../config/telegram_config.php';
    if (defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '' && TELEGRAM_BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE') {
        $hb = @filemtime('../../telegram/poll_heartbeat.txt');
        $tg_hb_stale = !$hb || (time() - $hb) > 300;
    }
} catch (Throwable $e) {}

// 附件標籤/浮水印功能欄位（首次使用自動補齊；詳見 src/common/attachment_lib.php）
require_once '../../src/common/attachment_lib.php';
try { eg_att_ensure_schema($conn->getPDO()); } catch (Throwable $e) { error_log('[createEvent] ensure att schema failed: ' . $e->getMessage()); }
$show_attach_inline = (int)($ev['show_attach_inline'] ?? 0);
// 編輯時的既有公告附件（含標籤/說明）
$notice_files = [];
if ($is_edit) {
    $notice_files = $conn->getAll("SELECT * FROM live_event_file WHERE live_event_id = " . (int)$eventid . " ORDER BY id");
}

// 編輯時的既有共同編輯者（[{type,id,name}]）
$selected_editors = [];
if ($is_edit) {
    try {
        foreach ($conn->getAll("SELECT editor_type, editor_id FROM live_event_editor WHERE live_event_id = " . (int)$eventid . " ORDER BY id") as $ed) {
            $edName = $ed['editor_type'] === 'dept'
                ? ($dept_name_map[$ed['editor_id']] ?? ('部門#' . $ed['editor_id']))
                : ($user_name_map[$ed['editor_id']] ?? ('人員#' . $ed['editor_id']));
            $selected_editors[] = ['type' => $ed['editor_type'], 'id' => (int)$ed['editor_id'], 'name' => $edName];
        }
    } catch (Exception $e) {}
}

// 搜尋
$result = null;
if (isset($_POST['btn_go_events'])) {
    $find = $_POST["sreach_events"];
    $stmt = $db->prepare("SELECT * FROM live_event WHERE title LIKE :find OR content LIKE :find ORDER BY eventdate DESC");
    $stmt->bindValue(':find', '%' . $find . '%', PDO::PARAM_STR);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="zh-TW">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>公告 / 通知管理</title>

    <!-- PWA manifest（Web Push / 加入主畫面用）-->
    <link rel="manifest" href="../../manifest.json">

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- iCheck -->
    <link href="../../resource/css/green.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="../../resource/css/select2.min.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <?php /* pages.css 不存在（404），多數頁面已註解；2026-07-07 一併移除 */ ?>

    <style>
        /* 本頁避免水平捲軸；用 clip 不會產生額外的捲動容器(避免雙層垂直捲軸) */
        html, body { overflow-x: clip; max-width: 100%; }
        .right_col { overflow-x: clip; }
        .select2-container { max-width: 100%; }

        /* ===== 公告/通知管理：現代化版面（精簡） ===== */
        /* 變數放 :root，讓 body 層級的 modal/overlay 也能取用 */
        :root {
            --eg-accent: #1ABB9C;
            --eg-accent-d: #169a80;
            --eg-dark: #2A3F54;
            --eg-bg: #f4f7f9;
            --eg-line: #e6ecf1;
            --eg-text: #34495e;
            --eg-muted: #8a9bab;
        }
        .eg-notice {
            padding: 16px 24px 50px;
            color: var(--eg-text);
            box-sizing: border-box;
        }
        .eg-notice, .eg-notice *, .eg-notice *::before, .eg-notice *::after { box-sizing: border-box; }

        .eg-head {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        .eg-head h1 { margin: 0; font-size: 20px; font-weight: 700; color: var(--eg-dark); display: flex; align-items: center; gap: 10px; }
        .eg-head h1 .eg-head-ico { width: 34px; height: 34px; line-height: 34px; text-align: center; border-radius: 9px; color: #fff; font-size: 16px; background: linear-gradient(135deg, var(--eg-accent), #2A9D8F); }
        .eg-head h1 small { display: block; font-size: 12px; font-weight: 400; color: var(--eg-muted); margin-top: 2px; }
        .eg-head-left { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .eg-myrole { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--eg-muted); }
        .eg-myrole-label { font-weight: 600; }
        .eg-myrole .rolepill { background: rgba(26,187,156,.13); color: var(--eg-accent-d); border-radius: 20px; padding: 3px 11px; font-size: 12px; font-weight: 600; }
        .eg-myrole .rolepill-admin { background: rgba(231,76,60,.12); color: #c0392b; }
        .eg-myrole .rolepill-none { background: #eef2f5; color: #8a9bab; }
        .eg-role-help { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: var(--eg-dark); color: #fff !important; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; transition: background .15s; }
        .eg-role-help:hover { background: var(--eg-accent-d); }

        /* 角色權限說明 modal */
        #roleHelpModal .modal-dialog { width: 640px; max-width: 96%; }
        #roleHelpModal .modal-header { background: var(--eg-dark); color: #fff; border-radius: 6px 6px 0 0; }
        #roleHelpModal .modal-header .close { color: #fff; opacity: .9; }
        #roleHelpModal .modal-body { padding: 0; max-height: 64vh; overflow-y: auto; }
        .rh-table { width: 100%; border-collapse: collapse; }
        .rh-table th { font-size: 12px; color: var(--eg-muted); text-align: center; padding: 10px 8px; border-bottom: 2px solid var(--eg-line); background: #f8f9fa; }
        .rh-table th:first-child { text-align: left; padding-left: 16px; }
        .rh-table td { padding: 10px 8px; border-bottom: 1px solid var(--eg-line); text-align: center; font-size: 13px; }
        .rh-table td:first-child { text-align: left; padding-left: 16px; font-weight: 600; color: var(--eg-dark); }
        .rh-table tr.is-mine { background: #f0faf7; }
        .rh-yes { color: var(--eg-accent-d); font-weight: 700; }
        .rh-no { color: #d7dde2; }
        .rh-mine-tag { font-size: 10px; background: var(--eg-accent); color: #fff; border-radius: 3px; padding: 1px 5px; margin-left: 6px; vertical-align: middle; }
        .rh-sys-tag { font-size: 10px; background: #f0ad4e; color: #fff; border-radius: 3px; padding: 1px 5px; margin-left: 6px; vertical-align: middle; }

        .eg-search { display: flex; align-items: center; background: #fff; border: 1px solid var(--eg-line); border-radius: 30px; padding: 3px 5px 3px 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .eg-search .fa { color: var(--eg-muted); }
        .eg-search input[type=text] { border: none; outline: none; background: transparent; padding: 6px 9px; width: 230px; font-size: 13px; }
        #eg-search-clear { display: none; width: 22px; height: 22px; line-height: 20px; text-align: center; border-radius: 50%; background: #eef2f5; color: #8a9bab; font-size: 16px; text-decoration: none; margin-right: 4px; }
        #eg-search-clear:hover { background: #e74c3c; color: #fff; }
        .eg-search.has-text #eg-search-clear { display: inline-block; }
        .eg-head-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .eg-btn-perm { border: 1px solid var(--eg-line); background: #fff; color: var(--eg-dark); border-radius: 30px; padding: 7px 16px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s; }
        .eg-btn-perm:hover { background: #f4f7f9; border-color: var(--eg-accent); color: var(--eg-accent-d); }

        /* 權限設定 modal */
        #permModal .modal-dialog { width: 720px; max-width: 96%; }
        #permModal .modal-header { background: var(--eg-dark); color: #fff; border-radius: 6px 6px 0 0; }
        #permModal .modal-header .close { color: #fff; opacity: .9; }
        .perm-wrap { display: flex; gap: 0; border: 1px solid var(--eg-line); border-radius: 8px; overflow: hidden; min-height: 320px; }
        .perm-roles { width: 230px; border-right: 1px solid var(--eg-line); display: flex; flex-direction: column; }
        .perm-roles-head { padding: 9px 12px; background: #f8f9fa; border-bottom: 1px solid var(--eg-line); display: flex; align-items: center; justify-content: space-between; font-size: 13px; font-weight: 600; color: var(--eg-dark); }
        .perm-roles-list { flex: 1; overflow-y: auto; }
        .perm-role-item { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; cursor: pointer; display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
        .perm-role-item:hover { background: #f5f8fa; }
        .perm-role-item.active { background: #e8f4f1; font-weight: 600; }
        .perm-role-item .sys { font-size: 10px; background: #f0ad4e; color: #fff; border-radius: 3px; padding: 1px 5px; margin-left: 5px; }
        .perm-role-del { border: none; background: transparent; color: #e74c3c; opacity: .6; cursor: pointer; padding: 0 4px; }
        .perm-role-del:hover { opacity: 1; }
        .perm-role-edit { border: none; background: transparent; color: #d68910; opacity: .6; cursor: pointer; padding: 0 4px; }
        .perm-role-edit:hover { opacity: 1; }

        /* 刪除角色確認 overlay */
        #permDelOverlay { display: none; position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,.5); }
        .pdo-box { width: 480px; max-width: 94%; margin: 8vh auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
        .pdo-head { background: #e74c3c; color: #fff; padding: 12px 16px; font-weight: 600; }
        .pdo-body { padding: 16px; font-size: 14px; color: #34495e; max-height: 60vh; overflow-y: auto; }
        .pdo-userlist { background: #f8f9fa; border: 1px solid #e6ecf1; border-radius: 8px; padding: 10px 12px; margin: 8px 0; max-height: 160px; overflow-y: auto; }
        .pdo-userlist .u { display: inline-block; background: #fff; border: 1px solid #e6ecf1; border-radius: 20px; padding: 2px 10px; margin: 3px 4px 0 0; font-size: 12.5px; }
        .pdo-label { font-size: 13px; font-weight: 600; color: #34495e; display: block; margin: 12px 0 5px; }
        .pdo-foot { padding: 12px 16px; border-top: 1px solid #eee; text-align: right; }
        .pdo-foot .eg-btn { margin-left: 8px; }
        #pdo-confirm-input { letter-spacing: 4px; text-align: center; font-weight: 700; width: 90px; }
        .perm-feats { flex: 1; display: flex; flex-direction: column; }
        .perm-feats-head { padding: 10px 14px; background: #f8f9fa; border-bottom: 1px solid var(--eg-line); font-size: 13px; font-weight: 600; color: var(--eg-dark); }
        .perm-feats-body { flex: 1; padding: 14px; }
        .perm-feats-body label { display: flex; align-items: center; gap: 8px; font-weight: 400; font-size: 14px; margin-bottom: 12px; cursor: pointer; }
        .perm-feats-foot { padding: 10px 14px; border-top: 1px solid var(--eg-line); background: #f8f9fa; text-align: right; }
        .perm-add-btn { border: none; background: var(--eg-accent); color: #fff; border-radius: 6px; padding: 3px 10px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .perm-add-btn:hover { background: var(--eg-accent-d); }
        .perm-save-btn { border: none; background: var(--eg-accent); color: #fff; border-radius: 7px; padding: 7px 18px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .perm-save-btn:hover { background: var(--eg-accent-d); }
        .perm-save-btn:disabled { opacity: .5; cursor: not-allowed; }

        .eg-card { background: #fff; border: 1px solid var(--eg-line); border-radius: 11px; box-shadow: 0 2px 8px rgba(42,63,84,.05); margin-bottom: 18px; overflow: hidden; }
        /* 新增/修改區塊：全寬、左右兩欄 */
        .eg-form-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; }
        .eg-form-2col .eg-col { display: flex; flex-direction: column; gap: 12px; }
        .eg-form-2col .eg-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 992px) { .eg-form-2col { grid-template-columns: 1fr; } }

        /* 各對象通知方式：三個拖放區塊 */
        .eg-tmzones { display: flex; flex-direction: column; gap: 8px; }
        .eg-tmzone { border: 1px solid var(--eg-line); border-radius: 9px; overflow: hidden; transition: border-color .12s, background .12s; }
        .eg-tmzone.dragover { border-color: var(--eg-accent); background: #f0faf7; }
        .eg-tmzone-head { padding: 6px 11px; font-size: 12.5px; font-weight: 700; color: var(--eg-dark); background: #f8f9fa; border-bottom: 1px solid var(--eg-line); }
        .eg-tmzone[data-mode="sign"] .eg-tmzone-head { color: #c77c1a; }
        .eg-tmzone[data-mode="reply"] .eg-tmzone-head { color: #7a4fc0; }
        .eg-tmzone-body { min-height: 40px; padding: 7px 9px; display: flex; flex-wrap: wrap; gap: 5px; align-content: flex-start; }
        .eg-tmzone-body:empty::after { content: '拖曳對象到此'; color: #c3ccd4; font-size: 11.5px; }
        .eg-tmchip { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 600; padding: 2px 9px; border-radius: 20px; background: #eef2f5; color: #5a6b7b; cursor: grab; user-select: none; border: 1.5px solid transparent; }
        .eg-tmchip:active { cursor: grabbing; }
        .eg-tmchip.sel { border-color: var(--eg-accent); background: rgba(26,187,156,.14); color: var(--eg-accent-d); }
        .eg-tmchip.dragging { opacity: .45; }
        .eg-tmchip .fa { font-size: 10px; color: #aab4bd; }
        .eg-card-head { width: 100%; display: flex; align-items: center; gap: 8px; padding: 12px 18px; border-bottom: 1px solid var(--eg-line); }
        .eg-card-head .fa { color: var(--eg-accent); font-size: 15px; }
        .eg-card-head h2 { margin: 0; font-size: 15px; font-weight: 700; color: var(--eg-dark); }
        .eg-card-body { padding: 16px 18px; }

        /* 表單（精簡） */
        .eg-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 18px; }
        .eg-field { display: flex; flex-direction: column; }
        .eg-field.full { grid-column: 1 / -1; }
        .eg-field label { font-size: 12.5px; font-weight: 600; color: var(--eg-text); margin-bottom: 5px; }
        .eg-field label .req { color: #e74c3c; margin-left: 2px; }
        .eg-field label .hint { font-weight: 400; color: var(--eg-muted); margin-left: 6px; }
        .eg-input, .eg-textarea {
            width: 100%; border: 1px solid var(--eg-line); border-radius: 8px;
            padding: 8px 11px; font-size: 13.5px; color: var(--eg-text); background: #fff;
            transition: border-color .15s, box-shadow .15s; font-family: inherit;
        }
        .eg-textarea { resize: vertical; min-height: 88px; line-height: 1.55; }
        .eg-input:focus, .eg-textarea:focus { border-color: var(--eg-accent); box-shadow: 0 0 0 3px rgba(26,187,156,.15); outline: none; }
        input[type=date].eg-input { cursor: pointer; }
        input[type=date].eg-input::-webkit-calendar-picker-indicator { cursor: pointer; opacity: .7; }

        .eg-check { font-weight: 400 !important; font-size: 12.5px; display: flex; align-items: center; gap: 6px; cursor: pointer; padding-top: 6px; }
        input[type=file].eg-input { padding: 7px 9px; font-size: 12.5px; cursor: pointer; }
        .eg-existing-files { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
        .eg-file-chip { display: inline-flex; align-items: center; gap: 6px; background: #f0f4f7; border: 1px solid var(--eg-line); border-radius: 7px; padding: 3px 9px; font-size: 12px; }
        .eg-file-chip a { color: var(--eg-accent-d); text-decoration: none; }
        .eg-file-chip a:hover { text-decoration: underline; }
        .eg-file-chip .del { font-weight: 400; color: #e74c3c; margin: 0; cursor: pointer; display: inline-flex; align-items: center; gap: 3px; }

        .eg-actions { display: flex; justify-content: flex-end; gap: 9px; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--eg-line); }
        .eg-btn { border: none; border-radius: 8px; padding: 8px 20px; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: all .15s; }
        .eg-btn-primary { background: var(--eg-accent); color: #fff; }
        .eg-btn-primary:hover { background: var(--eg-accent-d); box-shadow: 0 4px 12px rgba(26,187,156,.3); }
        .eg-btn-primary:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
        .eg-btn-ghost { background: #fff; color: var(--eg-muted); border: 1px solid var(--eg-line); }
        .eg-btn-ghost:hover { background: #f4f7f9; color: var(--eg-text); }

        /* Select2 對齊樣式 */
        .select2-container { width: 100% !important; }
        .select2-container--default .select2-selection--multiple {
            border: 1px solid var(--eg-line); border-radius: 8px; min-height: 38px; padding: 1px 4px;
        }
        .select2-container--default.select2-container--focus .select2-selection--multiple { border-color: var(--eg-accent); box-shadow: 0 0 0 3px rgba(26,187,156,.15); }
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: rgba(26,187,156,.13); border: none; color: var(--eg-accent-d); border-radius: 6px; padding: 2px 8px; font-size: 12.5px; margin: 3px 4px 0 0;
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color: var(--eg-accent-d); margin-right: 4px; }
        .select2-dropdown { border-color: var(--eg-line); }
        .select2-results__group { font-weight: 700; color: var(--eg-dark); }

        /* 列表（單列、緊湊） */
        .eg-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .eg-table thead th { font-size: 12px; font-weight: 700; color: var(--eg-muted); letter-spacing: .03em; text-align: left; padding: 11px 14px; border-bottom: 2px solid var(--eg-line); }
        .eg-table tbody td { padding: 11px 14px; vertical-align: middle; border-bottom: 1px solid var(--eg-line); }
        .eg-table tbody tr:hover td { background: #fafcfd; }
        .eg-date { font-size: 12.5px; color: var(--eg-text); white-space: nowrap; }
        .eg-date .end { color: var(--eg-muted); font-size: 11.5px; }
        .eg-row-title { font-size: 14px; font-weight: 600; color: var(--eg-dark); margin-bottom: 3px; word-break: break-word; }
        .eg-row-content { font-size: 12.5px; line-height: 1.6; color: var(--eg-muted); display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: pre-line; word-break: break-word; }
        .eg-targets { display: flex; flex-wrap: wrap; gap: 4px; }
        .eg-pill { display: inline-block; font-size: 11.5px; font-weight: 600; padding: 2px 9px; border-radius: 20px; background: #eef2f5; color: #5a6b7b; white-space: nowrap; }
        .eg-pill-all { background: rgba(26,187,156,.13); color: var(--eg-accent-d); }
        .eg-pill-dept { background: #e8f0fe; color: #3b6cc4; }
        .eg-pill-status { background: #fff3df; color: #c77c1a; }
        .eg-pill-user { background: #f0eafc; color: #7a4fc0; }
        /* 列表中人員標籤縮小，避免人員過多顯示混亂 */
        .eg-targets .eg-pill-user { font-size: 10.5px; padding: 1px 7px; font-weight: 500; }
        .eg-targets .eg-pill-user .fa { display: none; }
        .eg-read { font-size: 13px; color: var(--eg-muted); white-space: nowrap; }
        .eg-read .fa { margin-right: 3px; }
        a.eg-read-link { text-decoration: none; color: var(--eg-accent-d); font-weight: 600; }
        a.eg-read-link:hover { color: var(--eg-accent); text-decoration: underline; }
        a.eg-row-title-link { text-decoration: none; }
        a.eg-row-title-link:hover .eg-row-title { color: var(--eg-accent-d); text-decoration: underline; }

        /* 已讀人員 modal（含回簽 / 回覆結果）；以 backdrop:false 開啟（不壓暗頁面），故加強陰影邊界 */
        #readersModal .modal-dialog { width: 1040px; max-width: 96vw; }
        #readersModal .modal-content { box-shadow: 0 10px 48px rgba(20, 35, 50, .5); border: 1px solid #b9c5d0; }
        /* 跳窗開著時不鎖頁面捲動（Bootstrap 預設 overflow:hidden 會把頁面拉回頂部＝「下半部被遮蔽」的真正原因） */
        body.modal-open { overflow-y: auto !important; padding-right: 0 !important; }
        /* 已讀跳窗容器不攔截滑鼠：跳窗開著時頁面其餘部分可正常捲動與點擊，只有跳窗本體可互動 */
        #readersModal { pointer-events: none; overflow: visible; }
        #readersModal .modal-dialog { pointer-events: auto; }
        #readersModal .modal-header { background: var(--eg-dark); color: #fff; border-radius: 6px 6px 0 0; }
        #readersModal .modal-header .close { color: #fff; opacity: .9; }
        #readersModal .modal-body { max-height: 66vh; overflow-y: auto; padding: 0; }
        .rd-table { width: 100%; border-collapse: collapse; }
        .rd-table th { font-size: 12px; color: var(--eg-muted); text-align: left; padding: 10px 14px; border-bottom: 2px solid var(--eg-line); white-space: nowrap; background: #f8f9fa; position: sticky; top: 0; }
        .rd-table td { padding: 9px 14px; border-bottom: 1px solid var(--eg-line); font-size: 13px; vertical-align: top; }
        .rd-time { white-space: nowrap; color: var(--eg-text); }
        .rd-badge { display: inline-block; font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 20px; white-space: nowrap; }
        .rd-badge .fa { margin-right: 3px; }
        .rd-badge-read { background: #eef2f5; color: #5a6b7b; }
        .rd-badge-sign { background: #fff3df; color: #c77c1a; }
        .rd-badge-reply { background: #f0eafc; color: #7a4fc0; }
        .rd-reply-text { white-space: pre-line; word-break: break-word; color: var(--eg-text); line-height: 1.5; }
        .rd-reply-files { margin-top: 5px; display: flex; flex-wrap: wrap; gap: 5px; }
        .rd-file { display: inline-flex; align-items: center; gap: 4px; background: #f0f4f7; border: 1px solid var(--eg-line); border-radius: 7px; padding: 2px 8px; font-size: 12px; color: var(--eg-accent-d); text-decoration: none; }
        .rd-file:hover { text-decoration: underline; }
        .rd-empty { padding: 36px; text-align: center; color: var(--eg-muted); }
        /* 已讀人員：行內展開面板（取代跳窗，不遮蔽頁面任何內容） */
        .eg-readers-tr > td { background: #f2f7fc !important; padding: 0 !important; border-bottom: 2px solid var(--eg-accent, #1ABB9C) !important; }
        .eg-readers-panel { border-left: 4px solid var(--eg-accent, #1ABB9C); }
        .eg-readers-head { display: flex; justify-content: space-between; align-items: center; padding: 9px 14px; font-size: 13.5px; color: var(--eg-dark, #2A3F54); background: #e8f1fa; }
        .eg-readers-head .fa { margin-right: 4px; }
        .eg-readers-close { font-size: 12.5px; color: #c0392b; text-decoration: none; white-space: nowrap; }
        .eg-readers-close:hover { text-decoration: underline; color: #c0392b; }
        .eg-readers-body { max-height: 46vh; overflow-y: auto; background: #fff; }
        .eg-op { text-align: center; white-space: nowrap; }
        .eg-op a { text-decoration: none; display: inline-block; }
        .eg-op .eg-mini { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 6px; margin: 0 2px; text-align: center; transition: all .15s; }
        .eg-mini-edit { background: #fff3df; color: #d68910; }
        .eg-mini-edit:hover { background: #ffe9c7; }
        .eg-mini-del { background: #fdecea; color: #e74c3c; }
        .eg-mini-del:hover { background: #fbd9d4; }
        .eg-mini-hist { background: #eef2f5; color: #5a6b7b; }
        .eg-mini-hist:hover { background: #e1e8ee; }
        .eg-src { display: inline-block; font-size: 12px; font-weight: 600; color: #5a6b7b; background: #f0f4f7; border-radius: 5px; padding: 2px 8px; white-space: nowrap; }
        .eg-creator { font-size: 13px; color: var(--eg-text); white-space: nowrap; }

        /* 列表工具列（來源篩選/每頁/匯出/翻頁）放右上 */
        .eg-list-head { justify-content: space-between; flex-wrap: wrap; gap: 8px; }
        /* 公告者欄：名字換行不外溢；共同編輯者另起一行小字，避免與「對象」欄重疊 */
        .eg-table td.eg-creator { max-width: 130px; white-space: normal; word-break: break-all; }
        .eg-creator .eg-coeds { display: block; margin-top: 2px; font-size: 11px; color: #8a97a5; line-height: 1.4; }
        .eg-date .eg-ctime { display: block; font-size: 11px; color: #9aa7b3; }
        /* 內容區至少滿版高，跳窗背板下不露出深色 body 底 */
        .right_col { min-height: 100vh; }
        .eg-list-title-wrap { display: flex; align-items: center; gap: 8px; }
        .eg-list-tools { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
        .eg-tool-select { border: 1px solid var(--eg-line); border-radius: 7px; padding: 5px 9px; font-size: 12.5px; color: var(--eg-text); background: #fff; cursor: pointer; }
        .eg-tool-btn { border: 1px solid var(--eg-line); background: #fff; color: var(--eg-text); border-radius: 7px; padding: 5px 11px; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: all .15s; }
        .eg-tool-btn:hover { background: #f4f7f9; border-color: var(--eg-accent); color: var(--eg-accent-d); }
        .eg-pager { display: inline-flex; align-items: center; gap: 4px; }
        .eg-pager .pg { min-width: 28px; height: 28px; line-height: 26px; text-align: center; border: 1px solid var(--eg-line); border-radius: 6px; background: #fff; color: var(--eg-text); font-size: 12.5px; cursor: pointer; padding: 0 6px; }
        .eg-pager .pg:hover { background: #f4f7f9; }
        .eg-pager .pg.active { background: var(--eg-accent); color: #fff; border-color: var(--eg-accent); cursor: default; }
        .eg-pager .pg.disabled { opacity: .4; cursor: not-allowed; }
        .eg-pager .pg-info { font-size: 12px; color: var(--eg-muted); margin: 0 4px; }

        /* 修改歷史 modal */
        #histModal .modal-dialog { width: 1300px; max-width: 96vw; }
        #histModal .modal-header { background: var(--eg-dark); color: #fff; border-radius: 6px 6px 0 0; }
        #histModal .modal-header .close { color: #fff; opacity: .9; }
        #histModal .modal-body { max-height: 66vh; overflow-y: auto; padding: 0; }
        .hist-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .hist-table th { position: sticky; top: 0; background: #f8f9fa; color: var(--eg-muted); font-weight: 700; text-align: left; padding: 9px 12px; border-bottom: 2px solid var(--eg-line); }
        .hist-table td { padding: 8px 12px; border-bottom: 1px solid var(--eg-line); vertical-align: top; }
        .hist-table tr:hover td { background: #fafcfd; }
        .hist-time { color: var(--eg-muted); white-space: nowrap; }
        .hist-evt { font-weight: 600; color: var(--eg-dark); }
        .hist-chg { display: block; line-height: 1.7; }
        .hist-chg + .hist-chg { margin-top: 2px; }
        .hist-chg b { color: var(--eg-muted); font-weight: 600; }
        .hist-chg .old { color: #c0392b; background: #fdf0ee; border-radius: 4px; padding: 0 5px; }
        .hist-chg .new { color: var(--eg-accent-d); background: #eefaf6; border-radius: 4px; padding: 0 5px; }
        .eg-empty { text-align: center; padding: 44px 20px; color: var(--eg-muted); }
        .eg-empty .fa { font-size: 38px; color: #d7e0e7; display: block; margin-bottom: 10px; }

        @media (max-width: 768px) {
            .eg-notice { padding: 14px 12px 44px; }
            .eg-form-grid { grid-template-columns: 1fr; }
            .eg-search input[type=text] { width: 140px; }
        }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">

            <!-- side and top bar include -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>
            <!-- /side and top bar include -->

            <!-- page content -->
            <div class="right_col" role="main">
                <div class="eg-notice">

                    <!-- 標題列 + 搜尋 -->
                    <div class="eg-head">
                        <div class="eg-head-left">
                            <h1>
                                <span class="eg-head-ico"><i class="fa fa-bullhorn"></i></span>
                                <span>公告 / 通知管理<small>發布、編輯與管理全公司公告與通知</small></span>
                            </h1>
                            <span class="eg-myrole">
                                <span class="eg-myrole-label">您的角色：</span>
                                <?php if ($effective_bootstrap_admin) : ?>
                                    <span class="rolepill rolepill-admin">管理員（系統初始）</span>
                                <?php elseif (!empty($my_notice_roles)) : ?>
                                    <?php foreach ($my_notice_roles as $rn) : ?>
                                        <span class="rolepill<?= $rn === '管理員' ? ' rolepill-admin' : '' ?>"><?= htmlspecialchars($rn) ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="rolepill rolepill-none">未指派角色</span>
                                <?php endif; ?>
                                <a href="javascript:;" class="eg-role-help" data-toggle="modal" data-target="#roleHelpModal" title="各角色權限說明">?</a>
                            </span>
                        </div>
                        <div class="eg-head-right">
                            <?php if ($tg_hb_stale) : ?>
                                <span style="background:#fdecea;color:#c0392b;border:1px solid #f5b7b1;border-radius:14px;padding:4px 12px;font-size:12.5px;font-weight:700;"
                                      title="Telegram 輪詢心跳超過 5 分鐘未更新：按鈕回覆/回簽與附件索取暫時沒有反應（推播發送不受影響）。輪詢會在有人瀏覽系統頁面時自動重啟；若持續異常請檢查伺服器。">
                                    <i class="fa fa-exclamation-triangle"></i> Telegram 推播服務異常
                                </span>
                            <?php endif; ?>
                            <button type="button" class="eg-btn-perm" id="eg-push-btn" title="開啟瀏覽器推播通知（關閉網頁也能收到公告）"><i class="fa fa-bell-o"></i> <span id="eg-push-btn-label">開啟通知</span></button>
                            <?php if ($IS_ADMIN) : ?>
                                <button type="button" class="eg-btn-perm" data-toggle="modal" data-target="#permModal" title="設定角色與權限"><i class="fa fa-key"></i> 權限設定</button>
                                <button type="button" class="eg-btn-perm" id="eg-settings-btn" title="附件儲存位置設定"><i class="fa fa-cog"></i> 設定</button>
                                <?php // Telegram 已於 2026-07-07 恢復啟用（原 2026-07-06 暫停測試） ?>
                                <button type="button" class="eg-btn-perm" onclick="location.href='../../telegram/get_chat_id.php'" title="Telegram 推播綁定與測試（與 Web Push 並行）"><i class="fa fa-paper-plane"></i> Telegram</button>
                            <?php endif; ?>
                            <?php if ($can_manage) : ?>
                                <button type="button" class="eg-btn-perm" id="eg-hist-all-btn" title="所有公告修改歷史"><i class="fa fa-history"></i> 歷史紀錄</button>
                                <button type="button" class="eg-btn-perm" id="eg-subs-btn" title="已開啟推播通知的裝置清單"><i class="fa fa-bell"></i> 訂閱裝置</button>
                            <?php endif; ?>
                            <div class="eg-search">
                                <i class="fa fa-search"></i>
                                <input id="eg-search-input" type="text" placeholder="即時搜尋 標題/內容/來源/公告者..." autocomplete="off">
                                <a href="javascript:;" id="eg-search-clear" title="清除搜尋（或在欄內雙擊清除）">&times;</a>
                            </div>
                        </div>
                    </div>

                    <!-- 新增 / 修改（依角色權限） -->
                    <?php if (($CAN_CREATE && !$is_edit) || ($CAN_EDIT && $is_edit)) : ?>
                        <div class="eg-card eg-card-form">
                            <div class="eg-card-head"><i class="fa <?= $is_edit ? 'fa-pencil' : 'fa-plus-circle' ?>"></i>
                                <h2><?= $is_edit ? '修改公告 / 通知' : '新增公告 / 通知' ?></h2>
                            </div>
                            <div class="eg-card-body">
                                <form method="POST" action="" enctype="multipart/form-data" novalidate>
                                    <input type="hidden" id="eventid" name="eventid" value="<?= $eventid ?>">
                                    <div class="eg-form-2col">
                                        <!-- 左區塊：日期 / 期限 / 標題 / 內容 -->
                                        <div class="eg-col">
                                            <div class="eg-2">
                                                <div class="eg-field">
                                                    <label for="eventdate">發布日期 <span class="req">*</span></label>
                                                    <input type="date" id="eventdate" name="eventdate" class="eg-input" value="<?= $eventdate ?: ($is_edit ? '' : date('Y-m-d')) ?>" required>
                                                </div>
                                                <div class="eg-field">
                                                    <label for="enddate">結束日期 <span class="hint">留空＝長期</span></label>
                                                    <input type="date" id="enddate" name="enddate" class="eg-input" value="<?= $enddate ?>">
                                                </div>
                                            </div>
                                            <div class="eg-2">
                                                <div class="eg-field">
                                                    <label for="reply_deadline">回覆 / 回簽期限 <span class="hint">可留空</span></label>
                                                    <input type="date" id="reply_deadline" name="reply_deadline" class="eg-input" value="<?= $reply_deadline ?>">
                                                </div>
                                                <div class="eg-field">
                                                    <label>對象互看狀態</label>
                                                    <label class="eg-check"><input type="checkbox" name="show_status_to_others" value="1" <?= $show_status ? 'checked' : '' ?>> 對象可互看回覆/回簽/已閱</label>
                                                </div>
                                            </div>
                                            <div class="eg-field">
                                                <label for="title">標題 <span class="req">*</span></label>
                                                <input type="text" id="title" name="title" class="eg-input" value="<?= htmlspecialchars($title) ?>" maxlength="100" required>
                                            </div>
                                            <div class="eg-field">
                                                <label for="content">內容 <span class="req">*</span><span class="hint" id="content-count" style="font-weight:400;"></span></label>
                                                <textarea id="content" name="content" class="eg-textarea" style="min-height:120px;" maxlength="2000" required><?= htmlspecialchars($content) ?></textarea>
                                            </div>
                                            <div class="eg-field">
                                                <label for="notice_files">公告附件
                                                    <span class="hint">圖片(jpg/png)、PDF、Excel、Word，單檔 ≤ 20MB；Excel/Word 上傳後會轉成 PDF（需預覽確認）</span>
                                                    <button type="button" id="att-tag-manage-btn" class="eg-tool-btn" style="display:none;margin-left:6px;" title="附件標籤管理（僅主管）"><i class="fa fa-tags"></i> 標籤管理</button>
                                                </label>
                                                <input type="file" id="notice_files" multiple class="eg-input" accept=".jpg,.jpeg,.png,.pdf,.xls,.xlsx,.doc,.docx">
                                                <input type="hidden" name="att_items" id="att_items" value="">
                                                <div id="att-new-list"></div>
                                                <?php if ($is_edit && !empty($notice_files)) : ?>
                                                    <div class="eg-existing-files" id="att-exist-list">
                                                        <?php foreach ($notice_files as $f) : ?>
                                                            <span class="egatt-chip" id="efile-<?= $f['id'] ?>" data-fid="<?= $f['id'] ?>">
                                                                <a href="../../src/store/_eventFile.php?t=e&id=<?= $f['id'] ?>" target="_blank" class="egatt-name"><i class="fa fa-paperclip"></i> <?= htmlspecialchars($f['file_name']) ?></a>
                                                                <select class="egatt-tag egatt-exist-tag" data-fid="<?= $f['id'] ?>" data-cur="<?= (int)($f['tag_id'] ?? 0) ?>" title="附件標籤"><option>標籤載入中…</option></select>
                                                                <input type="text" class="egatt-desc egatt-exist-desc" data-fid="<?= $f['id'] ?>" value="<?= htmlspecialchars($f['description'] ?? '') ?>" maxlength="255" placeholder="附件說明(選填)" title="修改後自動儲存">
                                                                <a href="javascript:;" class="del eg-file-del egatt-del" data-fid="<?= $f['id'] ?>" title="刪除此附件">&times;</a>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <label class="eg-check" style="margin-top:7px;">
                                                    <input type="checkbox" name="show_attach_inline" value="1" <?= $show_attach_inline ? 'checked' : '' ?>>
                                                    附件 2 件（含）以下時，於檢視畫面直接顯示附件（僅電腦版）
                                                </label>
                                            </div>
                                        </div>

                                        <!-- 右區塊：對象 + 各對象通知方式（拖放分配）-->
                                        <div class="eg-col">
                                            <div class="eg-field">
                                                <label for="targets">對象 <span class="req">*</span> <span class="hint">可多選：部門 / 職稱 / 人員，或選「全體」</span></label>
                                                <select id="targets" name="targets[]" multiple required>
                                                    <option value="all" <?= in_array('all', $selected_targets) ? 'selected' : '' ?>>全體（所有人）</option>
                                                    <optgroup label="部門">
                                                        <?php foreach ($departments as $d) : $path = getDeptPath($d['id'], $deptMap); ?>
                                                            <option value="dept-<?= $d['id'] ?>" <?= in_array('dept-' . $d['id'], $selected_targets) ? 'selected' : '' ?>><?= htmlspecialchars($path) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                    <optgroup label="職稱">
                                                        <?php foreach ($statuses as $s) : ?>
                                                            <option value="status-<?= $s['id'] ?>" <?= in_array('status-' . $s['id'], $selected_targets) ? 'selected' : '' ?>><?= htmlspecialchars($s['title']) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                    <optgroup label="人員">
                                                        <?php foreach ($users as $u) :
                                                            $upath = getDeptPath($u['department_id'] ?? 0, $deptMap);
                                                            $upos = $u['position_name'] ?? '未指定'; ?>
                                                            <option value="user-<?= $u['id'] ?>" <?= in_array('user-' . $u['id'], $selected_targets) ? 'selected' : '' ?>><?= htmlspecialchars($u['user_cname']) ?>（<?= htmlspecialchars($upath) ?> / <?= htmlspecialchars($upos) ?>）</option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                </select>
                                            </div>
                                            <div class="eg-field">
                                                <label>各對象通知方式 <span class="hint">拖曳標籤到區塊；可先點選多個再拖</span></label>
                                                <div class="eg-tmzones">
                                                    <div class="eg-tmzone" data-mode="read">
                                                        <div class="eg-tmzone-head"><i class="fa fa-eye"></i> 已閱（預設）</div>
                                                        <div class="eg-tmzone-body" id="zone-read"></div>
                                                    </div>
                                                    <div class="eg-tmzone" data-mode="sign">
                                                        <div class="eg-tmzone-head"><i class="fa fa-pencil-square-o"></i> 回簽</div>
                                                        <div class="eg-tmzone-body" id="zone-sign"></div>
                                                    </div>
                                                    <div class="eg-tmzone" data-mode="reply">
                                                        <div class="eg-tmzone-head"><i class="fa fa-comments-o"></i> 回覆 + 回簽</div>
                                                        <div class="eg-tmzone-body" id="zone-reply"></div>
                                                    </div>
                                                </div>
                                                <input type="hidden" id="target_modes" name="target_modes" value="">
                                            </div>
                                            <div class="eg-field">
                                                <label for="co_editors_sel">共同編輯者 <span class="hint">可選部門或最多 5 位人員；共同編輯者可修改此公告（皆留編輯記錄）</span></label>
                                                <select id="co_editors_sel" multiple>
                                                    <optgroup label="部門">
                                                        <?php foreach ($departments as $d) : $path = getDeptPath($d['id'], $deptMap); ?>
                                                            <option value="dept-<?= $d['id'] ?>"><?= htmlspecialchars($path) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                    <optgroup label="人員">
                                                        <?php foreach ($users as $u) : ?>
                                                            <option value="user-<?= $u['id'] ?>"><?= htmlspecialchars($u['user_cname']) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                </select>
                                                <input type="hidden" id="co_editors" name="co_editors" value="">
                                                <div style="display:flex;align-items:center;gap:6px;margin-top:7px;flex-wrap:wrap;">
                                                    <select id="editor-preset-sel" class="eg-tool-select" style="min-width:170px;" title="套用預設共同編輯名單（私人名單優先顯示）">
                                                        <option value="">套用預設名單…</option>
                                                    </select>
                                                    <button type="button" id="editor-preset-save" class="eg-tool-btn" title="將目前共同編輯者存成預設名單（可設公開或私人）"><i class="fa fa-star-o"></i> 儲存名單</button>
                                                    <button type="button" id="editor-preset-del" class="eg-tool-btn" title="刪除選取的預設名單（僅能刪除自己建立的）"><i class="fa fa-trash-o"></i> 刪除名單</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="eg-actions">
                                        <button name="resetEvent" type="submit" class="eg-btn eg-btn-ghost"><?= $is_edit ? '取消' : '清除' ?></button>
                                        <?php if ($is_edit) : ?>
                                            <button id="send" name="upDateEvent" type="submit" class="eg-btn eg-btn-primary"><i class="fa fa-check"></i> 儲存修改</button>
                                        <?php else : ?>
                                            <button id="send" name="newEvent" type="submit" class="eg-btn eg-btn-primary"><i class="fa fa-paper-plane"></i> 送出發布</button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 列表（AJAX 分頁，進頁只載第 1 頁）-->
                    <div class="eg-card" id="eg-list-card">
                        <div class="eg-card-head eg-list-head">
                            <span class="eg-list-title-wrap"><i class="fa fa-list-ul"></i> <h2 id="eg-list-title">公告 / 通知列表</h2></span>
                            <div class="eg-list-tools">
                                <select id="eg-filter-source" class="eg-tool-select" title="來源篩選">
                                    <option value="">所有來源</option>
                                </select>
                                <?php if ($can_manage) : ?>
                                    <button type="button" id="eg-src-pref-btn" class="eg-tool-btn" title="來源顯示設定：勾選的來源不顯示於「所有來源」列表（於下拉選單指定該來源仍可查看；不影響推播通知）"><i class="fa fa-filter"></i></button>
                                <?php endif; ?>
                                <select id="eg-page-size" class="eg-tool-select" title="每頁筆數">
                                    <option value="5">5 筆</option>
                                    <option value="10" selected>10 筆</option>
                                    <option value="20">20 筆</option>
                                    <option value="50">50 筆</option>
                                </select>
                                <button type="button" id="eg-export-csv" class="eg-tool-btn" title="匯出 CSV"><i class="fa fa-file-excel-o"></i> CSV</button>
                                <button type="button" id="eg-export-pdf" class="eg-tool-btn" title="列印 / PDF"><i class="fa fa-file-pdf-o"></i> PDF</button>
                                <span class="eg-pager" id="eg-pager"></span>
                            </div>
                        </div>
                        <div class="eg-card-body" style="padding:0;">
                            <table class="eg-table">
                                <thead>
                                    <tr>
                                        <th style="width:120px">發布 / 結束</th>
                                        <th style="width:100px">來源</th>
                                        <th style="width:130px">公告者</th>
                                        <th style="width:150px">對象</th>
                                        <th>標題 / 內容</th>
                                        <th style="width:60px">已讀</th>
                                        <th style="width:170px">操作</th>
                                    </tr>
                                </thead>
                                <tbody id="eg-list-tbody"></tbody>
                            </table>
                            <div id="eg-list-msg" class="eg-empty"><i class="fa fa-spinner fa-spin"></i></div>
                        </div>
                    </div>

                </div>
            </div>
            <!-- /page content -->

            <!-- footer content include -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content include -->
        </div>
    </div>

    <?php if ($IS_ADMIN) : ?>
    <!-- 設定 modal（附件儲存位置）-->
    <div class="modal fade" id="settingsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--eg-dark);color:#fff;border-radius:6px 6px 0 0;">
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-cog"></i> 公告 / 通知 設定</h4>
                </div>
                <div class="modal-body">
                    <label style="font-size:13px;font-weight:600;color:var(--eg-dark);">附件儲存基礎路徑</label>
                    <input type="text" id="set-base" class="eg-input" placeholder="\\主機\分享\...  或留空使用預設" style="font-family:monospace;">
                    <p style="font-size:12px;color:var(--eg-muted);margin:8px 0 0;">
                        <i class="fa fa-info-circle"></i> 網路磁碟請用 <b>UNC 路徑</b>（如 <code>\\excellentnas\生產課\BOM\ERP\公告通知\公告通知附件</code>）；
                        <b>不可用磁碟機代號（Z:）</b>，Apache 服務看不到對應磁碟。留空則存於專案 <code>uploads/notice</code>。<br>
                        公告附件會依 <b>公告編號</b> 建立子資料夾；回覆附件存於該公告的「回覆附件」子資料夾。
                    </p>
                    <div id="set-msg" style="margin-top:10px;font-size:13px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="eg-btn eg-btn-ghost" data-dismiss="modal">關閉</button>
                    <button type="button" class="eg-btn eg-btn-primary" id="set-save"><i class="fa fa-save"></i> 儲存並測試寫入</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        window.addEventListener('load', function() {
            if (typeof $ === 'undefined') return;
            $('#eg-settings-btn').on('click', function() {
                $('#set-msg').html('');
                $.get('../../src/store/_noticeSettings.php', { action: 'get' }, function(res) {
                    if (res && res.ok) $('#set-base').val(res.base || '');
                }, 'json');
                $('#settingsModal').modal('show');
            });
            $('#set-save').on('click', function() {
                var $b = $(this).prop('disabled', true);
                $('#set-msg').html('<i class="fa fa-spinner fa-spin"></i> 儲存並測試中...');
                $.post('../../src/store/_noticeSettings.php', { action: 'save', base: $('#set-base').val() }, function(res) {
                    $b.prop('disabled', false);
                    if (!res || !res.ok) { $('#set-msg').html('<span style="color:#e74c3c;">儲存失敗：' + (res && res.msg ? res.msg : '') + '</span>'); return; }
                    $('#set-base').val(res.base || '');
                    if (res.writable) $('#set-msg').html('<span style="color:#1a9a80;"><i class="fa fa-check-circle"></i> 已儲存，且測試寫入成功。</span>');
                    else $('#set-msg').html('<span style="color:#e67e22;"><i class="fa fa-exclamation-triangle"></i> 已儲存，但<b>測試寫入失敗</b>：' + (res.writable_msg || '') + '</span>');
                }, 'json').fail(function() { $b.prop('disabled', false); $('#set-msg').html('<span style="color:#e74c3c;">連線失敗</span>'); });
            });
        });
    </script>

    <!-- 權限設定 modal -->
    <div class="modal fade" id="permModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-key"></i> 角色與權限設定</h4>
                </div>
                <div class="modal-body">
                    <div class="perm-wrap">
                        <!-- 左：角色清單 -->
                        <div class="perm-roles">
                            <div class="perm-roles-head">
                                <span>角色清單</span>
                                <button type="button" class="perm-add-btn" onclick="permAddRole()"><i class="fa fa-plus"></i> 新增</button>
                            </div>
                            <div class="perm-roles-list" id="perm-roles-list">
                                <div class="text-center text-muted" style="padding:20px;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>
                            </div>
                        </div>
                        <!-- 右：功能勾選 -->
                        <div class="perm-feats">
                            <div class="perm-feats-head" id="perm-feats-head">← 請選擇角色</div>
                            <div class="perm-feats-body">
                                <?php foreach ($PAGE_FEATURES as $f) : ?>
                                    <label><input type="checkbox" class="perm-feat-cb" value="<?= htmlspecialchars($f['code']) ?>"> <?= htmlspecialchars($f['label']) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="perm-feats-foot" id="perm-feats-foot" style="display:none;">
                                <button type="button" class="perm-save-btn" id="perm-save-btn" onclick="permSaveFeatures()"><i class="fa fa-save"></i> 儲存角色權限</button>
                            </div>
                        </div>
                    </div>
                    <p style="font-size:12px;color:#aaa;margin:10px 2px 0;">
                        <i class="fa fa-info-circle"></i> 使用者要指派角色，請至「管理設定 → 使用者權限」頁面操作。角色為全系統共用。
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- 刪除角色確認 overlay -->
    <div id="permDelOverlay">
        <div class="pdo-box">
            <div class="pdo-head"><i class="fa fa-exclamation-triangle"></i> 刪除角色「<span id="pdo-name"></span>」</div>
            <div class="pdo-body">
                <div id="pdo-users-wrap">
                    <span class="pdo-label" id="pdo-users-title">目前指定為此角色的人員：</span>
                    <div class="pdo-userlist" id="pdo-userlist"></div>
                </div>
                <div id="pdo-transfer-wrap" style="display:none;">
                    <span class="pdo-label">處理方式</span>
                    <select id="pdo-transfer" class="eg-input">
                        <option value="">直接移除此角色（這些人員將不再擁有此角色）</option>
                    </select>
                </div>
                <span class="pdo-label">請輸入大寫 <b style="color:#e74c3c;">Y</b> 以確認刪除</span>
                <input type="text" id="pdo-confirm-input" class="eg-input" maxlength="1" autocomplete="off" placeholder="Y">
            </div>
            <div class="pdo-foot">
                <button type="button" class="eg-btn eg-btn-ghost" onclick="permDelCancel()">取消</button>
                <button type="button" class="eg-btn" id="pdo-confirm-btn" style="background:#e74c3c;color:#fff;" disabled onclick="permDelConfirm()"><i class="fa fa-trash"></i> 確認刪除</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 角色權限說明 modal -->
    <div class="modal fade" id="roleHelpModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-question-circle"></i> 公告 / 通知　各角色權限說明</h4>
                </div>
                <div class="modal-body">
                    <table class="rh-table">
                        <thead>
                            <tr>
                                <th>角色</th>
                                <?php foreach ($PAGE_FEATURES as $f) : ?><th><?= htmlspecialchars(str_replace('公告/通知', '', $f['label'])) ?></th><?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_notice_roles)) : ?>
                                <tr><td colspan="<?= count($PAGE_FEATURES) + 1 ?>" style="text-align:center;color:#aaa;padding:24px;">尚無角色</td></tr>
                            <?php else : foreach ($all_notice_roles as $r) :
                                $mine = in_array($r['role_name'], $my_notice_roles, true) || ($effective_bootstrap_admin && $r['is_system']);
                                $hasAll = in_array('all', $r['features'], true);
                            ?>
                                <tr class="<?= $mine ? 'is-mine' : '' ?>">
                                    <td>
                                        <?= htmlspecialchars($r['role_name']) ?>
                                        <?php if ($r['is_system']) : ?><span class="rh-sys-tag">系統</span><?php endif; ?>
                                        <?php if ($mine) : ?><span class="rh-mine-tag">您的角色</span><?php endif; ?>
                                    </td>
                                    <?php foreach ($PAGE_FEATURES as $f) :
                                        $has = $hasAll || in_array($f['code'], $r['features'], true); ?>
                                        <td><?= $has ? '<span class="rh-yes">✓</span>' : '<span class="rh-no">—</span>' ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    <p style="font-size:12px;color:#aaa;padding:10px 16px;margin:0;">
                        <i class="fa fa-info-circle"></i> ✓ = 該角色擁有此功能；「系統」角色(管理員)擁有全部權限。使用者的最終權限為其所有角色的聯集。
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- 修改歷史 modal -->
    <div class="modal fade" id="histModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-history"></i> 修改歷史　<small id="histTitle" style="color:rgba(255,255,255,.8);"></small></h4>
                </div>
                <div class="modal-body" id="histBody">
                    <div class="eg-empty"><i class="fa fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 已讀人員 modal -->
    <div class="modal fade" id="readersModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-eye"></i> 已讀人員　<small id="readersTitle" style="color:rgba(255,255,255,.8);"></small></h4>
                </div>
                <div class="modal-body" id="readersBody">
                    <div class="rd-empty"><i class="fa fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($can_manage) : ?>
    <!-- 來源顯示設定 modal：勾選的來源不顯示於「所有來源」列表（不影響推播通知） -->
    <div class="modal fade" id="srcPrefModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document" style="width:520px;max-width:96vw;">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--eg-dark);color:#fff;border-radius:6px 6px 0 0;">
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-filter"></i> 來源顯示設定</h4>
                </div>
                <div class="modal-body" style="padding:16px 20px;">
                    <p style="font-size:12.5px;color:#8a97a5;margin-bottom:10px;">
                        勾選的來源在「所有來源」列表中<b>不顯示</b>（例如系統自動產生的通知洗版時可隱藏）。<br>
                        於來源下拉選單指定該來源時仍可查看全部；<b>推播通知不受影響、照常發送</b>。此設定為全站共用。
                    </p>
                    <div id="srcPrefList" style="max-height:46vh;overflow-y:auto;"><i class="fa fa-spinner fa-spin"></i></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="srcPrefSave"><i class="fa fa-check"></i> 儲存</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 已訂閱推播裝置 modal（分頁：已訂閱裝置 / 設定說明+自我診斷） -->
    <div class="modal fade" id="subsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--eg-dark);color:#fff;border-radius:6px 6px 0 0;">
                    <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-bell"></i> 推播通知　<small id="subsSummary" style="color:rgba(255,255,255,.8);"></small></h4>
                </div>
                <ul class="nav nav-tabs" id="subsTabs" style="padding:8px 14px 0;background:#f7f9fa;border-bottom:1px solid #ddd;">
                    <li class="active"><a href="#subsTabDevices" data-toggle="tab"><i class="fa fa-bell"></i> 已訂閱裝置</a></li>
                    <li><a href="#subsTabGuide" data-toggle="tab"><i class="fa fa-book"></i> 設定說明 / 自我診斷</a></li>
                </ul>
                <div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:0;">
                    <div class="tab-content">
                        <div class="tab-pane active" id="subsTabDevices">
                            <div id="subsBody">
                                <div class="rd-empty"><i class="fa fa-spinner fa-spin"></i></div>
                            </div>
                        </div>
                        <div class="tab-pane" id="subsTabGuide" style="padding:16px 22px;">
                            <?php include 'push_setup_guide.php'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- jQuery -->
    <script src="../../resource/js/jquery.min.js"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js"></script>
    <!-- FastClick -->
    <script src="../../resource/js/fastclick.js"></script>
    <!-- NProgress -->
    <script src="../../resource/js/nprogress.js"></script>
    <!-- Select2 -->
    <script src="../../resource/js/select2.min.js"></script>
    <!-- Custom Theme Scripts -->
    <script src="../../resource/js/custom.min.js"></script>

    <!-- Web Push 前端用戶端 -->
    <script>window.EG_PUSH_BASE = '../../';</script>
    <script src="../../resource/js/push-client.js"></script>

    <!-- 附件標籤/轉檔/預覽 共用元件（標籤管理跳窗、Excel工作表選擇、PDF預覽確認） -->
    <?php include '../common/attachment_ui.php'; ?>
    <script>
    $(function() {
        EGAtt.setup({
            scope: 'announcement',
            api: '../../src/store/notice_attachment_API.php',
            tagApi: '../../src/store/attachment_tag_API.php'
        });
        // 新上傳（尚未送出表單）的附件清單 [{upload_id, file_name, tag_id, description, converted}]
        var attItems = [];

        EGAtt.loadTags(false, function(r) {
            if (r.can_manage) $('#att-tag-manage-btn').show();
            // 填入既有附件的標籤下拉
            $('.egatt-exist-tag').each(function() {
                $(this).html(EGAtt.tagOptionsHtml($(this).data('cur') || ''));
            });
        });
        $('#att-tag-manage-btn').on('click', function() { EGAtt.openTagManager(); });

        // 選檔 → 逐檔走驗證/轉檔/預覽流程
        $('#notice_files').on('change', function() {
            var files = Array.prototype.slice.call(this.files || []);
            this.value = '';
            (function next(i) {
                if (i >= files.length) return;
                EGAtt.process(files[i], {}, function(item) {
                    attItems.push({ upload_id: item.upload_id, file_name: item.file_name, tag_id: '', description: '', converted: !!item.converted });
                    renderAttNew();
                    next(i + 1);
                }, function(msg) {
                    alert(files[i].name + '：' + msg);
                    next(i + 1);
                });
            })(0);
        });

        function renderAttNew() {
            $('#att-new-list').html(attItems.map(function(it, idx) {
                return '<span class="egatt-chip" data-idx="' + idx + '">'
                     + '<span class="egatt-name"><i class="fa fa-paperclip"></i> ' + EGAtt.esc(it.file_name) + '</span>'
                     + (it.converted ? '<span class="egatt-badge-conv" title="已由 Excel/Word 轉為 PDF">已轉PDF</span>' : '')
                     + '<select class="egatt-tag egatt-new-tag" data-idx="' + idx + '" title="附件標籤">' + EGAtt.tagOptionsHtml(it.tag_id) + '</select>'
                     + '<input type="text" class="egatt-desc egatt-new-desc" data-idx="' + idx + '" value="' + EGAtt.esc(it.description) + '" maxlength="255" placeholder="附件說明(選填)">'
                     + '<button type="button" class="egatt-del egatt-new-del" data-idx="' + idx + '" title="移除">&times;</button>'
                     + '</span>';
            }).join(''));
        }
        $(document).on('change', '.egatt-new-tag', function() { attItems[$(this).data('idx')].tag_id = this.value; });
        $(document).on('input',  '.egatt-new-desc', function() { attItems[$(this).data('idx')].description = this.value; });
        $(document).on('click',  '.egatt-new-del', function() {
            var idx = +$(this).data('idx');
            var it = attItems[idx];
            if (it) $.post('../../src/store/notice_attachment_API.php', { action: 'att_discard', upload_id: it.upload_id });
            attItems.splice(idx, 1);
            renderAttNew();
        });

        // 表單送出時把新附件清單（含標籤/說明）帶給後端綁定
        $('#att_items').closest('form').on('submit', function() {
            $('#att_items').val(JSON.stringify(attItems));
        });

        // 既有附件：標籤/說明修改即存（只影響未來的發送；會寫異動紀錄並重建檢視快取版）
        $(document).on('change', '.egatt-exist-tag', function() {
            var $s = $(this);
            $.post('../../src/store/notice_attachment_API.php', { action: 'att_set_meta', id: $s.data('fid'), tag_id: this.value }, function(r) {
                if (!r || !r.success) alert((r && r.message) || '標籤儲存失敗');
            }, 'json');
        });
        var descTimer = {};
        $(document).on('input', '.egatt-exist-desc', function() {
            var fid = $(this).data('fid'), val = this.value;
            clearTimeout(descTimer[fid]);
            descTimer[fid] = setTimeout(function() {
                $.post('../../src/store/notice_attachment_API.php', { action: 'att_set_meta', id: fid, description: val }, function(r) {
                    if (!r || !r.success) alert((r && r.message) || '說明儲存失敗');
                }, 'json');
            }, 600);
        });
    });
    </script>
    <script>
        // 「開啟通知」按鈕：顯示目前狀態並讓使用者主動授權
        $(function() {
            var $btn = $('#eg-push-btn'), $lbl = $('#eg-push-btn-label');
            var isiOS = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            var isStandalone = (window.navigator.standalone === true) || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
            var IOS_HINT = 'iPhone / iPad 要收推播：請用 Safari 開啟本系統 → 點下方「分享」→「加入主畫面」→ 再從主畫面的圖示開啟本 App，才會出現「開啟通知」。';
            function refreshBtn() {
                if (!window.EGPush) return;
                $btn.show();
                var s = EGPush.status();
                if (s === 'granted') { $lbl.text('通知已開啟'); $btn.find('i').attr('class', 'fa fa-bell'); $btn.attr('title', '通知已開啟（點擊可關閉此裝置通知）'); }
                else if (s === 'denied') { $lbl.text('通知被封鎖'); $btn.attr('title', '瀏覽器已封鎖通知，請至網站設定開啟'); }
                else if (s === 'insecure') { $lbl.text('需 HTTPS'); $btn.attr('title', '網頁推播需要安全連線(HTTPS)'); }
                else if (s === 'unsupported') {
                    if (isiOS && !isStandalone) { $lbl.text('需加入主畫面'); $btn.find('i').attr('class', 'fa fa-mobile'); $btn.attr('title', IOS_HINT); }
                    else { $btn.hide(); } // 舊版 iOS(<16.4) 或其他不支援環境
                }
                else { $lbl.text('開啟通知'); $btn.find('i').attr('class', 'fa fa-bell-o'); }
            }
            refreshBtn();
            $btn.on('click', function() {
                if (!window.EGPush) return;
                var s = EGPush.status();
                if (s === 'unsupported' && isiOS && !isStandalone) { alert(IOS_HINT); return; }
                if (s === 'granted') {
                    if (confirm('要關閉此裝置的公告推播通知嗎？')) { EGPush.disable().then(refreshBtn); }
                    return;
                }
                EGPush.enable().then(refreshBtn);
            });
            // 授權狀態可能於背景改變，載入後再刷新一次
            setTimeout(refreshBtn, 800);
        });
    </script>

    <script>
        $(function() {
            // 跳窗統一移到 body 底下（避免被版面容器的定位/裁切影響），關閉後清除殘留背板
            $('#readersModal,#subsModal,#histModal,#roleHelpModal,#permModal,#settingsModal,#srcPrefModal,#attTagModal,#attSheetModal,#attPrevModal').appendTo('body');
            $(document).on('hidden.bs.modal', '.modal', function() {
                if (!$('.modal.in').length) { $('.modal-backdrop').remove(); $('body').removeClass('modal-open'); }
            });

            var $t = $('#targets').select2({
                width: '100%',
                placeholder: '選擇對象（部門 / 職稱 / 人員，可多選）',
                closeOnSelect: false,
                allowClear: true
            });
            // 選了「全體」就清掉其他；選了其他就移除「全體」
            $t.on('select2:select', function(e) {
                if (e.params.data.id === 'all') {
                    $t.val(['all']).trigger('change');
                } else if ($t.val() && $t.val().indexOf('all') !== -1) {
                    var v = $t.val().filter(function(x) { return x !== 'all'; });
                    $t.val(v).trigger('change');
                }
            });

            // ===== 各對象通知方式：拖放到 已閱/回簽/回覆+回簽 三個區塊（支援多選拖移）=====
            var targetModes = <?= json_encode((object)($selected_target_modes ?? []), JSON_UNESCAPED_UNICODE) ?>;

            function egSyncTargetModes() {
                var out = {};
                $('.eg-tmzone-body').each(function() {
                    var mode = $(this).parent().data('mode');
                    $(this).children('.eg-tmchip').each(function() { out[$(this).data('code')] = mode; });
                });
                targetModes = out;
                $('#target_modes').val(JSON.stringify(out));
            }
            function egRenderZones() {
                // 無新增/編輯權限時表單（含 #targets）不會渲染；防呆避免中斷本區塊後續的列表載入
                var data = ($t.length && $t.select2('data')) || [];
                $('.eg-tmzone-body').empty();
                data.forEach(function(o) {
                    var mode = targetModes[o.id] || 'read';
                    var zone = mode === 'sign' ? '#zone-sign' : (mode === 'reply' ? '#zone-reply' : '#zone-read');
                    var chip = $('<span class="eg-tmchip" draggable="true"></span>')
                        .attr('data-code', o.id)
                        .html('<i class="fa fa-user-o"></i> ' + $('<i>').text(o.text).html());
                    $(zone).append(chip);
                });
                egSyncTargetModes();
            }
            $t.on('change', egRenderZones);

            // 點選 chip 多選（切換 .sel）
            $(document).on('click', '.eg-tmchip', function(e) { e.preventDefault(); $(this).toggleClass('sel'); });

            // 原生拖放
            var dragCodes = [];
            $(document).on('dragstart', '.eg-tmchip', function(e) {
                var $c = $(this);
                if (!$c.hasClass('sel')) { $('.eg-tmchip').removeClass('sel'); $c.addClass('sel'); }
                dragCodes = $('.eg-tmchip.sel').map(function() { return $(this).data('code'); }).get();
                $('.eg-tmchip.sel').addClass('dragging');
                try { e.originalEvent.dataTransfer.setData('text/plain', dragCodes.join(',')); } catch (x) {}
                e.originalEvent.dataTransfer.effectAllowed = 'move';
            });
            $(document).on('dragend', '.eg-tmchip', function() { $('.eg-tmchip').removeClass('dragging'); });
            $(document).on('dragover', '.eg-tmzone', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
            $(document).on('dragleave', '.eg-tmzone', function() { $(this).removeClass('dragover'); });
            $(document).on('drop', '.eg-tmzone', function(e) {
                e.preventDefault();
                var $zone = $(this).removeClass('dragover');
                var body = $zone.children('.eg-tmzone-body');
                (dragCodes || []).forEach(function(code) {
                    body.append($('.eg-tmchip[data-code="' + code + '"]'));
                });
                $('.eg-tmchip').removeClass('sel dragging');
                dragCodes = [];
                egSyncTargetModes();
            });

            egRenderZones();

            // ===== 共同編輯者（部門或最多 5 位人員）＋ 公開/私人預設名單 =====
            var PRESET_API = '../../src/store/_editorPreset.php';
            var egEditorNames = {}; // code => 顯示名（存名單用）
            $('#co_editors_sel option').each(function() { egEditorNames[$(this).val()] = $(this).text(); });
            var $ce = $('#co_editors_sel').select2({
                width: '100%',
                placeholder: '選擇共同編輯者（部門 / 人員，可留空）',
                closeOnSelect: false,
                allowClear: true
            });
            // 編輯時預選既有共同編輯者
            var egInitEditors = <?= json_encode($selected_editors ?? [], JSON_UNESCAPED_UNICODE) ?>;
            if (egInitEditors.length) {
                $ce.val(egInitEditors.map(function(e) { return e.type + '-' + e.id; })).trigger('change');
            }
            // 人員最多 5 位
            $ce.on('select2:select', function(e) {
                var users = ($ce.val() || []).filter(function(v) { return v.indexOf('user-') === 0; });
                if (users.length > 5) {
                    alert('共同編輯者的人員最多 5 位');
                    $ce.val(($ce.val() || []).filter(function(v) { return v !== e.params.data.id; })).trigger('change');
                }
            });
            function egCoEditorsJson() {
                return JSON.stringify(($ce.val() || []).map(function(v) {
                    var p = v.split('-');
                    return { type: p[0], id: parseInt(p[1], 10), name: egEditorNames[v] || '' };
                }));
            }
            // 預設名單：私人優先顯示（註明【私人】），下方為公開名單
            function egLoadPresets(sel) {
                $.get(PRESET_API, { action: 'list', module: 'notice' }, function(res) {
                    if (!res || !res.ok) return;
                    var h = '<option value="">套用預設名單…</option>';
                    (res.data || []).forEach(function(p) {
                        h += '<option value="' + p.id + '" data-own="' + (p.is_mine ? 1 : 0) + '">'
                           + (p.is_public == 1 ? '' : '【私人】') + egEsc(p.name) + '</option>';
                    });
                    $('#editor-preset-sel').html(h);
                    if (sel) $('#editor-preset-sel').val(String(sel));
                }, 'json');
            }
            egLoadPresets();
            $('#editor-preset-sel').on('change', function() {
                var pid = this.value;
                if (!pid) return;
                $.get(PRESET_API, { action: 'get', id: pid }, function(res) {
                    if (!res || !res.ok) { alert(res && res.msg ? res.msg : '載入名單失敗'); return; }
                    var codes = (res.editors || []).map(function(e) { return e.type + '-' + e.id; });
                    $ce.val(codes).trigger('change');
                }, 'json');
            });
            $('#editor-preset-save').on('click', function() {
                if (!($ce.val() || []).length) { alert('請先選擇共同編輯者再儲存名單'); return; }
                var name = prompt('請輸入此共同編輯名單的簡稱（提供選擇時顯示）：');
                if (name === null) return;
                name = name.trim();
                if (!name) { alert('名單簡稱不可空白'); return; }
                var isPublic = confirm('要設為「公開名單」讓其他使用者也能選用嗎？\n（確定＝公開，取消＝私人，僅自己可選）') ? 1 : 0;
                $.post(PRESET_API, { action: 'save', module: 'notice', name: name, is_public: isPublic, editors: egCoEditorsJson() }, function(res) {
                    if (!res || !res.ok) { alert(res && res.msg ? res.msg : '儲存失敗'); return; }
                    egLoadPresets(res.id);
                    alert('名單「' + name + '」已儲存為' + (isPublic ? '公開' : '私人') + '名單');
                }, 'json');
            });
            $('#editor-preset-del').on('click', function() {
                var pid = $('#editor-preset-sel').val();
                if (!pid) { alert('請先於下拉選單選取要刪除的名單'); return; }
                if ($('#editor-preset-sel option:selected').data('own') != 1) { alert('僅能刪除自己建立的名單'); return; }
                if (!confirm('確認刪除名單「' + $('#editor-preset-sel option:selected').text() + '」？')) return;
                $.post(PRESET_API, { action: 'delete', id: pid }, function(res) {
                    if (!res || !res.ok) { alert(res && res.msg ? res.msg : '刪除失敗'); return; }
                    egLoadPresets();
                }, 'json');
            });

            // 送出前：驗證必填欄位 + 同步通知方式（「取消 / 清除」不驗證）
            var _egSkipValidate = false;
            $(document).on('click', 'button[name="resetEvent"]', function() { _egSkipValidate = true; });
            $t.closest('form').on('submit', function(e) {
                if (_egSkipValidate) { _egSkipValidate = false; return; }
                var title = ($('#title').val() || '').trim();
                var content = ($('#content').val() || '').trim();
                if (!title) { alert('請輸入標題'); $('#title').focus(); e.preventDefault(); return false; }
                if (!content) { alert('請輸入內容'); $('#content').focus(); e.preventDefault(); return false; }
                if (!$t.val() || !$t.val().length) { alert('請選擇對象'); e.preventDefault(); return false; }
                egSyncTargetModes();
                $('#co_editors').val(egCoEditorsJson());
            });

            // 標題或內容未填時停用「送出」按鈕；顯示內容字數
            function egToggleSend() {
                var ok = ($('#title').val() || '').trim() !== '' && ($('#content').val() || '').trim() !== '';
                $('#send').prop('disabled', !ok);
                $('#content-count').text(($('#content').val() || '').length + ' / 2000');
            }
            $('#title, #content').on('input', egToggleSend);
            egToggleSend();

            // 刪除已上傳的公告附件（即時 AJAX）
            $(document).on('click', '.eg-file-del', function() {
                var fid = $(this).data('fid');
                if (!confirm('確認刪除此附件？')) return;
                var $chip = $('#efile-' + fid);
                $.post('../../src/store/_eventFileDelete.php', { id: fid }, function(res) {
                    if (res && res.ok) { $chip.remove(); }
                    else { alert('刪除失敗：' + (res && res.msg ? res.msg : '')); }
                }, 'json').fail(function() { alert('連線失敗'); });
            });

            // 已讀人員：改為「行內展開」——點列表的 👁 直接在該公告列下方展開清單
            // （不再使用跳窗：本版型的 modal 會與版面互相干擾造成頁面被遮蔽，2026-07-07 依使用者要求改顯示方式）
            $(document).on('click', '.eg-read-link', function() {
                var eid = $(this).data('eid');
                var title = $(this).data('title') || '';
                var $tr = $(this).closest('tr');
                // 再點一次同列 → 收合
                var $exist = $('#eg-readers-tr-' + eid);
                if ($exist.length) { $exist.remove(); return; }
                $('.eg-readers-tr').remove(); // 一次只展開一筆
                var $det = $('<tr class="eg-readers-tr" id="eg-readers-tr-' + eid + '"><td colspan="7"><div class="eg-readers-panel">'
                          + '<div class="eg-readers-head"><span><i class="fa fa-eye"></i> 已讀人員　<b>' + egEsc(title) + '</b></span>'
                          + '<a href="javascript:;" class="eg-readers-close" title="收合">收合 ✕</a></div>'
                          + '<div class="eg-readers-body"><div class="rd-empty"><i class="fa fa-spinner fa-spin"></i></div></div>'
                          + '</div></td></tr>');
                $tr.after($det);
                var $body = $det.find('.eg-readers-body');
                $.get('../../src/store/_eventReaders.php', { eventid: eid }, function(res) {
                    if (!res || !res.ok) { $body.html('<div class="rd-empty">載入失敗</div>'); return; }
                    if (!res.data.length) { $body.html('<div class="rd-empty"><i class="fa fa-eye-slash"></i> 尚無人閱讀</div>'); return; }
                    var dash = '<span style="color:#bbb;">—</span>';
                    var badge = {
                        read:  '<span class="rd-badge rd-badge-read"><i class="fa fa-eye"></i> 已閱</span>',
                        sign:  '<span class="rd-badge rd-badge-sign"><i class="fa fa-pencil-square-o"></i> 已回簽</span>',
                        reply: '<span class="rd-badge rd-badge-reply"><i class="fa fa-comments-o"></i> 已回覆</span>'
                    };
                    var h = '<table class="rd-table"><thead><tr>'
                          + '<th style="width:40px">#</th><th style="width:90px">人員</th><th style="width:78px">狀態</th>'
                          + '<th style="width:150px">已閱時間</th><th style="width:150px">回簽時間</th>'
                          + '<th style="width:150px">回覆時間</th><th>回覆內容 / 附件</th>'
                          + '</tr></thead><tbody>';
                    res.data.forEach(function(r, i) {
                        var reply = '';
                        if (r.reply_content) reply += '<div class="rd-reply-text">' + $('<i>').text(r.reply_content).html() + '</div>';
                        if (r.files && r.files.length) {
                            reply += '<div class="rd-reply-files">';
                            r.files.forEach(function(f) {
                                // 回覆附件：上傳者本人與最高管理者可刪（can_del 由後端判定，刪除權限亦由後端把關）
                                reply += '<span style="display:inline-flex;align-items:center;"><a class="rd-file" href="../../src/store/_eventFile.php?t=r&id=' + f.id + '" target="_blank"><i class="fa fa-paperclip"></i> ' + $('<i>').text(f.name).html() + '</a>'
                                       + (f.can_del ? '<button type="button" class="rd-rf-del" data-id="' + f.id + '" title="刪除此回覆附件" style="background:none;border:none;color:#e74c3c;cursor:pointer;padding:2px 6px;"><i class="fa fa-trash-o"></i></button>' : '')
                                       + '</span>';
                            });
                            reply += '</div>';
                        }
                        if (!reply) reply = dash;
                        // (系統管理員/測試用) 純「已閱」者旁附「改未閱」小按鈕；已回簽/回覆者不提供（後端亦會擋）
                        var unreadBtn = (EG.isAdmin && r.status === 'read' && r.user_id)
                            ? ' <a href="javascript:;" class="rd-unread-btn" data-eid="' + eid + '" data-uid="' + r.user_id + '" title="測試用：把此人的已閱重設為未閱" style="font-size:11px;color:#e67e22;white-space:nowrap;"><i class="fa fa-undo"></i> 改未閱</a>'
                            : '';
                        h += '<tr>'
                           + '<td>' + (i + 1) + '</td>'
                           + '<td>' + $('<i>').text(r.name).html() + '</td>'
                           + '<td>' + (badge[r.status] || badge.read) + unreadBtn + '</td>'
                           + '<td class="rd-time">' + (r.read_at || dash) + '</td>'
                           + '<td class="rd-time">' + (r.signed_at || dash) + '</td>'
                           + '<td class="rd-time">' + (r.replied_at || dash) + '</td>'
                           + '<td>' + reply + '</td>'
                           + '</tr>';
                    });
                    h += '</tbody></table>';
                    $body.html(h);
                }, 'json').fail(function() { $body.html('<div class="rd-empty">連線失敗</div>'); });
            });
            $(document).on('click', '.eg-readers-close', function() { $(this).closest('.eg-readers-tr').remove(); });

            // (系統管理員/測試用) 已讀人員清單「改未閱」：把該人此公告的已閱重設為未閱（後端 _eventReadReset.php 限管理員）
            $(document).on('click', '.rd-unread-btn', function() {
                var $a = $(this), eid = $a.data('eid'), uid = $a.data('uid');
                var name = ($a.closest('tr').find('td').eq(1).text() || '').trim();
                if (!confirm('確定將「' + name + '」的已閱改回未閱？（測試用，該人員會重新收到未讀通知）')) return;
                $a.css('pointer-events', 'none');
                $.post('../../src/store/_eventReadReset.php', { eventid: eid, userid: uid }, function(res) {
                    if (!res || !res.ok) { $a.css('pointer-events', ''); alert(res && res.msg ? res.msg : '重設失敗'); return; }
                    var $panel = $('#eg-readers-tr-' + eid);
                    $a.closest('tr').remove();
                    // 清單已空 → 顯示「尚無人閱讀」
                    if ($panel.length && !$panel.find('.rd-table tbody tr').length) {
                        $panel.find('.eg-readers-body').html('<div class="rd-empty"><i class="fa fa-eye-slash"></i> 尚無人閱讀</div>');
                    }
                    // 同步把列表「已讀」數字減 1
                    var $link = $('.eg-read-link[data-eid="' + eid + '"]');
                    if ($link.length) {
                        var n = parseInt($link.text().replace(/[^0-9]/g, ''), 10);
                        if (!isNaN(n) && n > 0) $link.html('<i class="fa fa-eye"></i> ' + (n - 1));
                    }
                }, 'json').fail(function() { $a.css('pointer-events', ''); alert('連線失敗'); });
            });

            // 刪除回覆附件（上傳者本人或最高管理者；權限由後端 _respFileDelete.php 把關）
            $(document).on('click', '.rd-rf-del', function() {
                var $b = $(this), id = $b.data('id');
                var name = ($b.prev('a.rd-file').text() || '').trim();
                if (!confirm('確定刪除附件「' + name + '」？刪除後無法復原。')) return;
                $b.prop('disabled', true);
                $.post('../../src/store/_respFileDelete.php', { id: id }, function(res) {
                    if (res && res.ok) { $b.parent('span').remove(); }
                    else { $b.prop('disabled', false); alert(res && res.msg ? res.msg : '刪除失敗'); }
                }, 'json').fail(function() { $b.prop('disabled', false); alert('連線失敗'); });
            });

            // 已訂閱裝置：顯示已開啟推播的人員與裝置類型
            $('#eg-subs-btn').on('click', function() {
                $('#subsSummary').text('');
                $('#subsBody').html('<div class="rd-empty"><i class="fa fa-spinner fa-spin"></i></div>');
                $('#subsModal').modal('show');
                $.get('../../src/store/_pushSubscribers.php', {}, function(res) {
                    if (!res || !res.ok) { $('#subsBody').html('<div class="rd-empty">' + (res && res.msg ? res.msg : '載入失敗') + '</div>'); return; }
                    if (!res.data.length) { $('#subsBody').html('<div class="rd-empty"><i class="fa fa-bell-slash-o"></i> 目前沒有任何裝置開啟推播</div>'); return; }
                    $('#subsSummary').text('共 ' + res.total + ' 台裝置 / ' + res.users + ' 人');
                    var e = function(s) { return $('<i>').text(s == null ? '' : s).html(); };
                    var h = '<table class="rd-table"><thead><tr>'
                          + '<th style="width:44px">#</th><th>人員</th><th style="width:170px">裝置</th>'
                          + '<th style="width:150px">開啟時間</th><th style="width:150px">最後推播</th></tr></thead><tbody>';
                    res.data.forEach(function(r, i) {
                        h += '<tr>'
                           + '<td>' + (i + 1) + '</td>'
                           + '<td>' + e(r.name) + '</td>'
                           + '<td><i class="fa ' + (r.icon || 'fa-desktop') + '"></i> ' + e(r.device) + '</td>'
                           + '<td class="rd-time">' + (r.created_at || '<span style="color:#bbb;">—</span>') + '</td>'
                           + '<td class="rd-time">' + (r.last_used_at || '<span style="color:#bbb;">—</span>') + '</td>'
                           + '</tr>';
                    });
                    h += '</tbody></table>';
                    $('#subsBody').html(h);
                }, 'json').fail(function() { $('#subsBody').html('<div class="rd-empty">連線失敗</div>'); });
            });

            // 「設定說明 / 自我診斷」分頁：切到該分頁（或開啟 modal 時已停在該分頁）就跑一次檢測
            $(document).on('shown.bs.tab', 'a[href="#subsTabGuide"]', function() {
                if (typeof egPushDiagRun === 'function') egPushDiagRun();
            });
            $('#subsModal').on('shown.bs.modal', function() {
                if ($('#subsTabGuide').hasClass('active') && typeof egPushDiagRun === 'function') egPushDiagRun();
            });

            // 修改歷史：搜尋欄左側「歷史紀錄」按鈕 → 顯示「所有公告」的修改歷史
            $('#eg-hist-all-btn').on('click', function() {
                $('#histTitle').text('所有公告');
                $('#histBody').html('<div class="eg-empty"><i class="fa fa-spinner fa-spin"></i></div>');
                $('#histModal').modal('show');
                $.get('../../src/store/_eventHistory.php', {}, function(res) {
                    if (!res || !res.ok) { $('#histBody').html('<div class="eg-empty">載入失敗</div>'); return; }
                    if (!res.data.length) { $('#histBody').html('<div class="eg-empty"><i class="fa fa-inbox"></i> 尚無修改紀錄</div>'); return; }
                    var fields = ['發布日期', '結束日期', '對象', '標題', '內容', '共同編輯者'];
                    var esc = function(s) { return $('<i>').text(s == null ? '' : s).html(); };
                    var nrm = function(s) { return (s == null ? '' : String(s)).replace(/\r\n?/g, '\n').trim(); };
                    var h = '<table class="hist-table"><thead><tr><th style="width:130px">時間</th><th style="width:90px">修改人</th><th>公告</th><th>變更內容（僅列有變動者）</th></tr></thead><tbody>';
                    res.data.forEach(function(it) {
                        // 只列有變動的欄位（忽略純換行/空白差異）
                        var changes = '';
                        if (it.before && it.after) {
                            fields.forEach(function(k) {
                                var o = it.before[k] || '', n = it.after[k] || '';
                                if (nrm(o) !== nrm(n)) {
                                    changes += '<span class="hist-chg"><b>' + k + '</b>：<span class="old">' + esc(o) + '</span> → <span class="new">' + esc(n) + '</span></span>';
                                }
                            });
                        }
                        if (!changes) changes = '<span class="text-muted" style="color:#bbb;">（無欄位變動）</span>';
                        h += '<tr>'
                           + '<td class="hist-time">' + esc(it.changed_at) + '</td>'
                           + '<td>' + esc(it.changed_by) + '</td>'
                           + '<td class="hist-evt">' + esc(it.event_title) + '</td>'
                           + '<td>' + changes + '</td>'
                           + '</tr>';
                    });
                    h += '</tbody></table>';
                    $('#histBody').html(h);
                }, 'json').fail(function() { $('#histBody').html('<div class="eg-empty">連線失敗</div>'); });
            });

            // ===== AJAX 分頁列表（進頁只載第 1 頁；搜尋/篩選/換頁/換筆數都向後端要該頁）=====
            var EG = {
                canManage: <?= $can_manage ? 'true' : 'false' ?>,
                isAdmin:   <?= $IS_ADMIN ? 'true' : 'false' ?>,
                canEdit:   <?= $CAN_EDIT ? 'true' : 'false' ?>,
                canDelete: <?= $CAN_DELETE ? 'true' : 'false' ?>,
                uid: <?= (int)$id ?>
            };
            var LIST_API = '../../src/store/_eventList.php';
            var egState = { page: 1, size: 10, kw: '', source: '', pages: 1, total: 0 };
            var $searchInput = $('#eg-search-input');

            function egEsc(s) { return $('<i>').text(s == null ? '' : s).html(); }

            function egRenderRows(rows) {
                if (!rows.length) {
                    $('#eg-list-tbody').empty();
                    $('#eg-list-msg').html('<i class="fa ' + ((egState.kw || egState.source) ? 'fa-search-minus' : 'fa-inbox') + '"></i> ' + ((egState.kw || egState.source) ? '找不到符合的公告 / 通知' : '目前沒有任何公告 / 通知')).show();
                    return;
                }
                $('#eg-list-msg').hide();
                var html = '';
                rows.forEach(function(r) {
                    // 發布日期加建立時間：同日只顯示 時:分，跨日顯示 月-日 時:分（用於區分多筆公告先後）
                    var ctime = '';
                    if (r.created_at) {
                        var cd = String(r.created_at).substring(0, 10), ct = String(r.created_at).substring(11, 16);
                        ctime = '<span class="eg-ctime" title="建立時間 ' + egEsc(r.created_at) + '"><i class="fa fa-clock-o"></i> '
                              + egEsc(cd === r.eventdate ? ct : cd.substring(5) + ' ' + ct) + '</span>';
                    }
                    var dateHtml = egEsc(r.eventdate) + ctime + (r.enddate ? '<br><span class="end">~ ' + egEsc(r.enddate) + '</span>' : '');
                    var pills = '';
                    (r.targets || []).forEach(function(t) {
                        var ico = t.cls === 'eg-pill-user' ? '<i class="fa fa-user"></i> ' : '';
                        pills += '<span class="eg-pill ' + t.cls + '">' + ico + egEsc(t.label) + '</span>';
                    });
                    // 公告者第一行；共同編輯者另起一行小字（避免與對象欄重疊）
                    var creatorHtml = egEsc(r.creator || '—');
                    if (r.editors && r.editors.length) {
                        creatorHtml += '<span class="eg-coeds" title="共同編輯者">共編：' + r.editors.map(egEsc).join('、') + '</span>';
                    }
                    html += '<tr>'
                        + '<td class="eg-date">' + dateHtml + '</td>'
                        + '<td><span class="eg-src">' + egEsc(r.source || '—') + '</span></td>'
                        + '<td class="eg-creator">' + creatorHtml + '</td>'
                        + '<td><div class="eg-targets">' + (pills || '<span class="eg-pill">—</span>') + '</div></td>'
                        + '<td><a class="eg-row-title-link" href="viewEvent.php?event=' + r.id + '" target="_blank" title="開啟檢視畫面（左側內容、右側附件）"><div class="eg-row-title">' + egEsc(r.title) + ' <i class="fa fa-external-link" style="font-size:11px;color:#9ab0c4;"></i></div></a><div class="eg-row-content">' + egEsc(r.content) + '</div></td>';
                    // 已讀欄：管理權限者可點開已讀人員清單，其他人只看數字
                    if (EG.canManage) {
                        html += '<td><a href="javascript:;" class="eg-read eg-read-link" data-eid="' + r.id + '" data-title="' + egEsc(r.title) + '" title="查看已讀人員"><i class="fa fa-eye"></i> ' + r.reads + '</a></td>';
                    } else {
                        html += '<td><span class="eg-read"><i class="fa fa-eye"></i> ' + r.reads + '</span></td>';
                    }
                    // 操作欄：逐列權限（只有系統管理員可改/刪任何公告；其他人僅本人建立或本人為共同編輯者）
                    html += '<td class="eg-op">';
                    if (r.source === '訂單變更') {
                        // 來源『訂單變更』的通知為衍生副本，鎖定禁止刪改（請至訂單頁作廢變更單，會連動移除通知）
                        html += '<span class="eg-mini" style="background:#eceff1;color:#90a4ae;cursor:not-allowed;" title="此通知由「訂單變更」產生已鎖定；如需移除請至訂單追蹤頁作廢該變更單"><i class="fa fa-lock"></i> 鎖定</span>';
                    } else {
                        var showEdit = false;
                        if (r.ref_type === 'QA' && r.ref_id) {
                            // 來源=品質異常單：修改導向品管合併檢驗頁的異常單修改畫面；
                            // 無異常單修改權限者，該頁會提示並引導向主管提出修改請求（原因必填），故不以 can_edit 擋按鈕
                            html += '<a href="../QC/inspection_combined_prototype.php?edit_abnormal=' + r.ref_id + '" target="_blank" title="開啟異常單修改畫面（無權限時可向主管提出修改請求）"><span class="eg-mini eg-mini-edit"><i class="fa fa-pencil"></i> 編輯</span></a>';
                            showEdit = true;
                        } else if (r.can_edit) {
                            html += '<a href="../../src/store/_updateEvent.php?eventid=' + r.id + '&id=' + EG.uid + '"><span class="eg-mini eg-mini-edit"><i class="fa fa-pencil"></i> 編輯</span></a>';
                            showEdit = true;
                        }
                        if (r.can_delete) html += '<a href="../../src/store/_deleteEvent.php?eventid=' + r.id + '&id=' + EG.uid + '" onclick="return confirm(\'確認刪除?\')"><span class="eg-mini eg-mini-del"><i class="fa fa-trash"></i> 刪除</span></a>';
                        if (!showEdit && !r.can_delete) html += '<span style="color:#c3ccd4;">—</span>';
                    }
                    html += '</td>';
                    html += '</tr>';
                });
                $('#eg-list-tbody').html(html);
            }

            function egRenderPager() {
                var p = egState.page, tp = egState.pages, h = '';
                h += '<span class="pg-info">共 ' + egState.total + ' 筆</span>';
                h += '<span class="pg ' + (p <= 1 ? 'disabled' : '') + '" data-pg="' + (p - 1) + '">‹</span>';
                var start = Math.max(1, p - 2), end = Math.min(tp, p + 2);
                if (start > 1) h += '<span class="pg" data-pg="1">1</span>' + (start > 2 ? '<span class="pg-info">…</span>' : '');
                for (var i = start; i <= end; i++) h += '<span class="pg ' + (i === p ? 'active' : '') + '" data-pg="' + i + '">' + i + '</span>';
                if (end < tp) h += (end < tp - 1 ? '<span class="pg-info">…</span>' : '') + '<span class="pg" data-pg="' + tp + '">' + tp + '</span>';
                h += '<span class="pg ' + (p >= tp ? 'disabled' : '') + '" data-pg="' + (p + 1) + '">›</span>';
                $('#eg-pager').html(h);
            }

            function egLoadList(page) {
                egState.page = page || 1;
                $('#eg-list-msg').html('<i class="fa fa-spinner fa-spin"></i>').show();
                $.get(LIST_API, { page: egState.page, size: egState.size, kw: egState.kw, source: egState.source }, function(res) {
                    if (!res || !res.ok) { $('#eg-list-tbody').empty(); $('#eg-list-msg').html(res && res.msg ? res.msg : '載入失敗').show(); return; }
                    egState.pages = res.pages; egState.total = res.total;
                    egRenderRows(res.rows);
                    egRenderPager();
                    $('#eg-list-title').text((egState.kw || egState.source ? '搜尋結果' : '公告 / 通知列表') + '（' + res.total + '）');
                    if (res.sources && res.sources.length && egState.page === 1) {
                        // 來源下拉：隱藏中的來源標註（隱藏中）——「所有來源」不含它們，指定選取仍可查看
                        var opt = '<option value="">所有來源</option>';
                        res.sources.forEach(function(s) {
                            var name = (typeof s === 'string') ? s : s.name;
                            var hid  = (typeof s === 'object') && s.hidden;
                            opt += '<option value="' + egEsc(name) + '">' + egEsc(name) + (hid ? '（隱藏中）' : '') + '</option>';
                        });
                        $('#eg-filter-source').html(opt).val(egState.source);
                    }
                }, 'json').fail(function() { $('#eg-list-tbody').empty(); $('#eg-list-msg').html('連線失敗').show(); });
            }

            $('#eg-pager').on('click', '.pg:not(.disabled):not(.active)', function() { var pg = parseInt($(this).data('pg'), 10); if (pg >= 1 && pg <= egState.pages) egLoadList(pg); });
            $('#eg-page-size').on('change', function() { egState.size = parseInt(this.value, 10) || 10; egLoadList(1); });
            $('#eg-filter-source').on('change', function() { egState.source = this.value; egLoadList(1); });

            // 來源顯示設定（管理權限者）：勾選＝隱藏於「所有來源」列表；不影響推播
            var SRC_PREF_API = '../../src/store/_noticeSourcePrefs.php';
            $('#eg-src-pref-btn').on('click', function() {
                $('#srcPrefList').html('<i class="fa fa-spinner fa-spin"></i>');
                $('#srcPrefModal').modal('show');
                $.get(SRC_PREF_API, { action: 'list' }, function(res) {
                    if (!res || !res.ok) { $('#srcPrefList').html('<span class="text-danger">' + egEsc((res && res.msg) || '載入失敗') + '</span>'); return; }
                    if (!(res.sources || []).length) { $('#srcPrefList').html('<span class="text-muted">目前沒有任何來源</span>'); return; }
                    $('#srcPrefList').html(res.sources.map(function(s) {
                        return '<label style="display:block;font-weight:normal;cursor:pointer;padding:4px 2px;margin:0;border-bottom:1px dashed #eee;">'
                             + '<input type="checkbox" class="src-pref-cb" value="' + egEsc(s.name) + '"' + (s.hidden ? ' checked' : '') + '> '
                             + egEsc(s.name) + (s.hidden ? ' <span style="color:#c77c1a;font-size:11px;">（目前隱藏）</span>' : '')
                             + '</label>';
                    }).join(''));
                }, 'json');
            });
            $('#srcPrefSave').on('click', function() {
                var hidden = $('#srcPrefList .src-pref-cb:checked').map(function() { return this.value; }).get();
                var $b = $(this).prop('disabled', true);
                $.post(SRC_PREF_API, { action: 'save', hidden: JSON.stringify(hidden) }, function(res) {
                    $b.prop('disabled', false);
                    if (!res || !res.ok) { alert((res && res.msg) || '儲存失敗'); return; }
                    $('#srcPrefModal').modal('hide');
                    egLoadList(1); // 重新載入列表與來源下拉
                }, 'json').fail(function() { $b.prop('disabled', false); alert('連線失敗'); });
            });

            function egSyncSearchUI() { var has = ($searchInput.val() || '').length > 0; $('.eg-search').toggleClass('has-text', has); $('.eg-card-form').toggle(!has); }
            var egSearchTimer = null;
            $searchInput.on('input', function() {
                egSyncSearchUI();
                clearTimeout(egSearchTimer);
                egSearchTimer = setTimeout(function() { egState.kw = ($searchInput.val() || '').trim(); egLoadList(1); }, 300);
            });
            $searchInput.on('dblclick', function() { if (($(this).val() || '') !== '') { $(this).val(''); egSyncSearchUI(); egState.kw = ''; egLoadList(1); } });
            $('#eg-search-clear').on('click', function() { $searchInput.val('').focus(); egSyncSearchUI(); egState.kw = ''; egLoadList(1); });

            // 匯出 CSV / 列印PDF（皆套用目前搜尋與來源篩選）
            $('#eg-export-csv').on('click', function() { window.location = LIST_API + '?export=csv&kw=' + encodeURIComponent(egState.kw) + '&source=' + encodeURIComponent(egState.source); });
            $('#eg-export-pdf').on('click', function() { window.open(LIST_API + '?export=print&kw=' + encodeURIComponent(egState.kw) + '&source=' + encodeURIComponent(egState.source), '_blank'); });

            // 進頁：只載第 1 頁
            egLoadList(1);

            // 後端擋下鎖定通知的刪改時，導回帶 locked=1 提示
            if (/[?&]locked=1/.test(window.location.search)) {
                alert('此通知由「訂單變更」產生已鎖定，不可在此修改或刪除；如需移除請至訂單追蹤頁作廢該變更單（會連動移除通知）。');
                if (window.history && history.replaceState) history.replaceState(null, '', window.location.pathname);
            }
            // 後端擋下非本人公告的刪改時，導回帶 denied=1 提示
            if (/[?&]denied=1/.test(window.location.search)) {
                alert('您不是此公告的公告者或共同編輯者，僅系統管理員可修改／刪除他人公告。');
                if (window.history && history.replaceState) history.replaceState(null, '', window.location.pathname);
            }
        });
    </script>

    <?php if ($IS_ADMIN) : ?>
    <script>
        // ===== 角色與權限設定（共用 Roles_API）=====
        var ROLES_API = '../../src/store/Roles_API.php';
        var NOTICE_MODULE = 'notice';
        var _permRoleId = null, _permRoleSystem = false;
        var _permRolesCache = [];
        var _pdoRoleId = null, _pdoUserIds = [];

        function permLoadRoles() {
            $('#perm-roles-list').html('<div class="text-center text-muted" style="padding:20px;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>');
            $.get(ROLES_API, { action: 'get_roles', module: NOTICE_MODULE }, function(res) {
                if (!res.success) { $('#perm-roles-list').html('<div class="text-danger" style="padding:10px;">載入失敗</div>'); return; }
                var html = '';
                _permRolesCache = res.data;
                res.data.forEach(function(r) {
                    var sys = r.is_system == 1;
                    var active = (_permRoleId == r.role_id) ? ' active' : '';
                    var nameEsc = String(r.role_name).replace(/'/g, "\\'");
                    html += '<div class="perm-role-item' + active + '" onclick="permSelectRole(' + r.role_id + ',' + (sys ? 1 : 0) + ',this)">'
                          + '<span>' + $('<i>').text(r.role_name).html() + (sys ? '<span class="sys">系統</span>' : '') + '</span>'
                          + (sys ? '' : '<span style="white-space:nowrap;">'
                                + '<button class="perm-role-edit" title="修正名稱" onclick="event.stopPropagation();permRenameRole(' + r.role_id + ',\'' + nameEsc + '\')"><i class="fa fa-pencil"></i></button>'
                                + '<button class="perm-role-del" title="刪除角色" onclick="event.stopPropagation();permDeleteRole(' + r.role_id + ',\'' + nameEsc + '\')"><i class="fa fa-times"></i></button>'
                              + '</span>')
                          + '</div>';
                });
                $('#perm-roles-list').html(html || '<div class="text-muted" style="padding:10px;font-size:12px;">尚無角色</div>');
            });
        }

        function permSelectRole(roleId, isSystem, el) {
            _permRoleId = roleId; _permRoleSystem = !!isSystem;
            $('.perm-role-item').removeClass('active');
            if (el) $(el).addClass('active');
            var name = el ? $(el).find('span').first().clone().children().remove().end().text() : '';
            $('#perm-feats-head').text('設定功能：' + name);
            $('.perm-feat-cb').prop('checked', false).prop('disabled', _permRoleSystem);
            $('#perm-feats-foot').show();
            if (_permRoleSystem) {
                $('.perm-feat-cb').prop('checked', true);
                $('#perm-feats-head').text('系統角色（管理員）：擁有全部功能，不可修改');
                $('#perm-save-btn').prop('disabled', true);
                return;
            }
            $('#perm-save-btn').prop('disabled', false);
            $.get(ROLES_API, { action: 'get_role_features', role_id: roleId }, function(res) {
                if (res.success && res.data) {
                    res.data.forEach(function(code) { $('.perm-feat-cb[value="' + code + '"]').prop('checked', true); });
                }
            });
        }

        function permSaveFeatures() {
            if (!_permRoleId || _permRoleSystem) return;
            var codes = [];
            $('.perm-feat-cb:checked').each(function() { codes.push($(this).val()); });
            $('#perm-save-btn').prop('disabled', true);
            $.post(ROLES_API, { action: 'save_role_features', role_id: _permRoleId, features: JSON.stringify(codes) }, function(res) {
                $('#perm-save-btn').prop('disabled', false);
                if (res.success) { alert('角色權限已儲存'); }
                else { alert('儲存失敗：' + (res.message || '')); }
            });
        }

        function permAddRole() {
            var name = prompt('請輸入角色名稱（例：公告管理員、部門主管）');
            if (!name || !name.trim()) return;
            $.post(ROLES_API, { action: 'save_role', role_name: name.trim(), module: NOTICE_MODULE }, function(res) {
                if (!res.success) { alert('新增失敗：' + (res.message || '')); return; }
                _permRoleId = res.role_id;
                permLoadRoles();
            });
        }

        function permRenameRole(roleId, currentName) {
            var name = prompt('修正角色名稱：', currentName);
            if (name === null) return;
            name = name.trim();
            if (!name) { alert('角色名稱不可空白'); return; }
            if (name === currentName) return;
            $.post(ROLES_API, { action: 'save_role', role_id: roleId, role_name: name }, function(res) {
                if (!res.success) { alert('修正失敗：' + (res.message || '')); return; }
                permLoadRoles();
            });
        }

        // 刪除角色：先列出此角色的人員，提供轉換角色選項，需輸入大寫 Y 確認
        function permDeleteRole(roleId, roleName) {
            _pdoRoleId = roleId; _pdoUserIds = [];
            $('#pdo-name').text(roleName);
            $('#pdo-confirm-input').val('');
            $('#pdo-confirm-btn').prop('disabled', true);
            $('#pdo-userlist').html('<i class="fa fa-spinner fa-spin"></i> 載入中...');
            $('#pdo-transfer-wrap').hide();
            $('#permDelOverlay').css('display', 'block');

            $.get(ROLES_API, { action: 'get_users' }, function(res) {
                var members = [];
                if (res.success && res.data) {
                    res.data.forEach(function(u) {
                        if (u.roles && u.roles.some(function(r) { return r.role_id == roleId; })) members.push(u);
                    });
                }
                _pdoUserIds = members.map(function(u) { return u.id; });
                if (members.length === 0) {
                    $('#pdo-users-title').text('目前沒有任何人員被指定為此角色。');
                    $('#pdo-userlist').html('<span class="text-muted" style="font-size:12px;">（無）</span>');
                    $('#pdo-transfer-wrap').hide();
                } else {
                    $('#pdo-users-title').text('目前指定為此角色的人員（共 ' + members.length + ' 位）：');
                    var h = '';
                    members.forEach(function(u) { h += '<span class="u">' + $('<i>').text(u.user_cname || ('#' + u.id)).html() + '</span>'; });
                    $('#pdo-userlist').html(h);
                    // 轉換角色下拉（排除自己）
                    var opt = '<option value="">直接移除此角色（這些人員將不再擁有此角色）</option>';
                    _permRolesCache.forEach(function(r) {
                        if (r.role_id != roleId) opt += '<option value="' + r.role_id + '">轉換為：' + $('<i>').text(r.role_name).html() + '</option>';
                    });
                    $('#pdo-transfer').html(opt);
                    $('#pdo-transfer-wrap').show();
                }
            });
        }

        $(document).on('input', '#pdo-confirm-input', function() {
            $('#pdo-confirm-btn').prop('disabled', $(this).val() !== 'Y');
        });

        function permDelCancel() { $('#permDelOverlay').css('display', 'none'); _pdoRoleId = null; }

        function permDelConfirm() {
            if ($('#pdo-confirm-input').val() !== 'Y' || !_pdoRoleId) return;
            var roleId = _pdoRoleId;
            var newRole = $('#pdo-transfer').val();
            $('#pdo-confirm-btn').prop('disabled', true);

            function doDelete() {
                $.post(ROLES_API, { action: 'delete_role', role_id: roleId }, function(res) {
                    if (!res.success) { alert('刪除失敗：' + (res.message || '')); $('#pdo-confirm-btn').prop('disabled', false); return; }
                    if (_permRoleId == roleId) { _permRoleId = null; $('#perm-feats-head').text('← 請選擇角色'); $('#perm-feats-foot').hide(); }
                    permDelCancel();
                    permLoadRoles();
                });
            }

            // 若選擇轉換角色，先把這些人員指派到新角色，再刪除原角色
            if (newRole && _pdoUserIds.length > 0) {
                var reqs = _pdoUserIds.map(function(uid) {
                    return $.post(ROLES_API, { action: 'assign_user_role', user_id: uid, role_id: newRole });
                });
                $.when.apply($, reqs).done(doDelete).fail(function() {
                    alert('轉換角色時發生錯誤，已取消刪除');
                    $('#pdo-confirm-btn').prop('disabled', false);
                });
            } else {
                doDelete();
            }
        }

        $('#permModal').on('shown.bs.modal', function() { permLoadRoles(); });
    </script>
    <?php endif; ?>
</body>
<?php if ($IS_ADMIN) : /* iPhone 推播測試按鈕：僅管理者顯示（原程式有 JS 語法錯誤導致整段失效並在所有人的 console 報錯，2026-07-07 修正） */ ?>
<script>
// 強制無視任何嚴格判斷，直接在畫面上建立一個絕對看得到的測試按鈕
const testBtn = document.createElement('button');
testBtn.innerText = '🔥 iPhone 強制啟用通知測試';
testBtn.style.cssText = 'position:fixed; bottom:20px; right:20px; z-index:9999; padding:15px; background:red; color:white; font-weight:bold; border-radius:5px; border:none;';
document.body.appendChild(testBtn);

testBtn.addEventListener('click', async () => {
    alert('開始偵測 Service Worker...');
    try {
        // 1. 強制註冊 Service Worker
        const reg = await navigator.serviceWorker.register('/EGsystem/push-sw.js');
        alert('Service Worker 註冊成功！開始向 Apple 申請通知權限...');
        
        // 2. 彈出 iPhone 原生通知權限視窗
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            alert('你點擊了拒絕通知，請去 iPhone 設定開啟！');
            return;
        }
        
        // 3. 取得 VAPID 公鑰並向 Apple 伺服器訂閱
        // （push_public_key.php 回傳 JSON {publicKey}；金鑰須轉為 Uint8Array，與 push-client.js 同法）
        alert('權限允許成功！正在產生裝置加密憑證...');
        const pubKeyRes = await fetch('/EGsystem/src/store/push_public_key.php');
        const pubKeyJson = await pubKeyRes.json();
        const pubKey = String(pubKeyJson.publicKey || '').trim();
        if (!pubKey) { alert('取不到 VAPID 公鑰，請確認 push_config.php 設定'); return; }
        function urlBase64ToUint8Array(s) {
            const padding = '='.repeat((4 - s.length % 4) % 4);
            const base64 = (s + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = window.atob(base64);
            const arr = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
            return arr;
        }

        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(pubKey)
        });
        
        // 4. 將完整憑證打包傳給後端
        alert('裝置憑證產生成功！準備寫入資料庫...');
        const subRes = await fetch('/EGsystem/src/store/push_subscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub)
        });
        
        const subJson = await subRes.json();
        alert('資料庫回報結果：' + JSON.stringify(subJson));
        
    } catch (err) {
        alert('報錯原因：' + err.message);
        console.error(err);
    }
});
</script>
<?php endif; ?>
</html>
