<?php
session_start();

if (!isset($_SESSION['userName']) && !isset($_SESSION['id'])) { // 未登入則返回登入頁
    $_SESSION['lastpage'] = "../../views/admin/home_page_setting.php";
    header("Location:../../index.php");
    exit();
}

include '../../src/common/DBConnection.php';
require_once '../../src/common/homepage.php';
require_once '../../src/common/rbac.php';

@$id = $_SESSION['id'];

$conn = new DBConnection();
$pdo  = $conn->getPDO();
hp_ensure_columns($pdo);

// === 角色權限（RBAC，module = homepage） ===
$_features  = rbac_user_features($pdo, (int)$id);
$CAN_VIEW   = rbac_has($_features, 'homepage_view');
$CAN_CREATE = rbac_has($_features, 'homepage_create');
$CAN_EDIT   = rbac_has($_features, 'homepage_edit');
$CAN_DELETE = rbac_has($_features, 'homepage_delete');
$IS_ADMIN   = rbac_has($_features, 'all');

// 無檢視權限 → 導回儀表板
// 注意：此處使用者「已登入」，不可設定 lastpage 再導回登入頁，否則會形成登入無限循環
if (!$CAN_VIEW) {
    header("Location:../../views/admin/dashboard.php");
    exit();
}

// 本頁功能清單（供「權限設定」勾選）
$PAGE_FEATURES = [
    ['code' => 'homepage_view',   'label' => '檢視首頁設定'],
    ['code' => 'homepage_create', 'label' => '新增首頁設定'],
    ['code' => 'homepage_edit',   'label' => '編輯首頁設定'],
    ['code' => 'homepage_delete', 'label' => '刪除首頁設定'],
];

// 目前使用者角色（標題列顯示）＋ bootstrap 判定
$my_roles = [];
$is_bootstrap = false;
try {
    $st = $pdo->prepare("SELECT r.role_name FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
                         WHERE ur.user_id = ? AND (r.module = 'homepage' OR r.is_system = 1)
                         ORDER BY r.is_system DESC, r.role_id ASC");
    $st->execute([(int)$id]);
    $my_roles = $st->fetchAll(PDO::FETCH_COLUMN);
    $anyAdmin = (int)$pdo->query("SELECT COUNT(*) FROM user_roles ur JOIN role_features rf ON rf.role_id = ur.role_id WHERE rf.feature_code = 'all'")->fetchColumn();
    $is_bootstrap = ($anyAdmin === 0);
} catch (Exception $e) {}
$effective_bootstrap_admin = $is_bootstrap && empty($my_roles);

// 所有角色 + 功能（? 說明跳窗用）
$all_roles = [];
try {
    foreach ($pdo->query("SELECT role_id, role_name, is_system FROM roles WHERE module = 'homepage' OR is_system = 1 ORDER BY is_system DESC, role_id ASC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rf = $pdo->prepare("SELECT feature_code FROM role_features WHERE role_id = ?");
        $rf->execute([$r['role_id']]);
        $r['features'] = $rf->fetchAll(PDO::FETCH_COLUMN);
        $all_roles[] = $r;
    }
} catch (Exception $e) {}

// === 資料 ===
$options = hp_options();
$hp_default = hp_get_default($pdo);

$departments = $pdo->query(
    "SELECT id, name, parent_id, level, home_page FROM department ORDER BY level ASC, sort_order ASC, name ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$deptMap = [];
foreach ($departments as $d) { $deptMap[$d['id']] = $d; }
if (!function_exists('getDeptPath')) {
    function getDeptPath($deptId, $deptMap) {
        if (empty($deptId) || !isset($deptMap[$deptId])) return '';
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

$users = $pdo->query(
    "SELECT u.id, u.user_cname, u.home_page, d.name AS department_name
     FROM `user` u
     LEFT JOIN user_department_position_map m ON u.id = m.user_id AND m.is_main = 1
     LEFT JOIN department d ON m.department_id = d.id
     WHERE u.state NOT IN (0, 90)
     ORDER BY u.user_cname ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// 路徑 → 標籤對照（列表顯示用）
$labelByPath = [];
foreach ($options as $o) { $labelByPath[$o['path']] = $o['label']; }
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>首頁設定</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link href="../../resource/css/pages.css" rel="stylesheet">
    <style>
        html, body { overflow-x: clip; max-width: 100%; }
        .right_col { overflow-x: clip; }
        :root {
            --eg-accent: #1ABB9C; --eg-accent-d: #169a80; --eg-dark: #2A3F54;
            --eg-bg: #f4f7f9; --eg-line: #e6ecf1; --eg-text: #34495e; --eg-muted: #8a9bab;
        }
        .eg-hp { padding: 16px 24px 50px; color: var(--eg-text); box-sizing: border-box; }
        .eg-hp, .eg-hp *, .eg-hp *::before, .eg-hp *::after { box-sizing: border-box; }
        .eg-head { width: 100%; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .eg-head h1 { margin: 0; font-size: 20px; font-weight: 700; color: var(--eg-dark); display: flex; align-items: center; gap: 10px; }
        .eg-head h1 .eg-head-ico { width: 34px; height: 34px; line-height: 34px; text-align: center; border-radius: 9px; color: #fff; font-size: 16px; background: linear-gradient(135deg, var(--eg-accent), #2A9D8F); }
        .eg-head h1 small { display: block; font-size: 12px; font-weight: 400; color: var(--eg-muted); margin-top: 2px; }
        .eg-head-left { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .eg-myrole { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--eg-muted); }
        .eg-myrole-label { font-weight: 600; }
        .eg-myrole .rolepill { background: rgba(26,187,156,.13); color: var(--eg-accent-d); border-radius: 20px; padding: 3px 11px; font-size: 12px; font-weight: 600; }
        .eg-myrole .rolepill-admin { background: rgba(231,76,60,.12); color: #c0392b; }
        .eg-myrole .rolepill-none { background: #eef2f5; color: #8a9bab; }
        .eg-role-help { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: var(--eg-dark); color: #fff !important; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .eg-role-help:hover { background: var(--eg-accent-d); }
        .eg-btn-perm { border: 1px solid var(--eg-line); background: #fff; color: var(--eg-dark); border-radius: 30px; padding: 7px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .eg-btn-perm:hover { background: #f4f7f9; border-color: var(--eg-accent); color: var(--eg-accent-d); }

        /* 版面：左設定 右預覽 */
        .hp-layout { display: grid; grid-template-columns: 1fr 460px; gap: 18px; align-items: start; }
        @media (max-width: 1200px) { .hp-layout { grid-template-columns: 1fr; } }
        .eg-card { background: #fff; border: 1px solid var(--eg-line); border-radius: 11px; box-shadow: 0 2px 8px rgba(42,63,84,.05); margin-bottom: 18px; overflow: hidden; }
        .eg-card-head { padding: 11px 16px; border-bottom: 1px solid var(--eg-line); font-weight: 700; color: var(--eg-dark); display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .eg-card-head .ttl { display: flex; align-items: center; gap: 8px; }
        .eg-card-body { padding: 14px 16px; }

        .hp-tabs { display: flex; gap: 6px; }
        .hp-tab { border: 1px solid var(--eg-line); background: #fff; border-radius: 20px; padding: 5px 15px; font-size: 13px; font-weight: 600; color: var(--eg-muted); cursor: pointer; }
        .hp-tab.active { background: var(--eg-accent); color: #fff; border-color: var(--eg-accent); }

        .hp-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .hp-search { display: flex; align-items: center; background: #fff; border: 1px solid var(--eg-line); border-radius: 30px; padding: 3px 5px 3px 12px; }
        .hp-search .fa { color: var(--eg-muted); }
        .hp-search input { border: none; outline: none; background: transparent; padding: 5px 8px; width: 180px; font-size: 13px; }
        .hp-select-sm { border: 1px solid var(--eg-line); border-radius: 6px; padding: 5px 8px; font-size: 13px; background: #fff; }
        .eg-btn-csv { border: 1px solid var(--eg-line); background: #fff; color: var(--eg-dark); border-radius: 20px; padding: 5px 13px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
        .eg-btn-csv:hover { border-color: var(--eg-accent); color: var(--eg-accent-d); }

        table.hp-table { width: 100%; border-collapse: collapse; }
        table.hp-table th { font-size: 12px; color: var(--eg-muted); text-align: left; padding: 9px 10px; border-bottom: 2px solid var(--eg-line); background: #f8f9fa; }
        table.hp-table td { padding: 8px 10px; border-bottom: 1px solid var(--eg-line); font-size: 13px; vertical-align: middle; }
        table.hp-table tr:hover td { background: #f7fbfa; }
        .hp-dept-name { font-weight: 600; color: var(--eg-dark); }
        .hp-dept-path { display: block; font-size: 11px; color: var(--eg-muted); }
        .hp-sel { border: 1px solid var(--eg-line); border-radius: 7px; padding: 6px 8px; font-size: 13px; background: #fff; min-width: 210px; max-width: 100%; }
        .hp-sel.dirty { border-color: #f0ad4e; background: #fffdf5; }
        .hp-row-btn { border: none; border-radius: 6px; padding: 5px 10px; font-size: 12px; font-weight: 600; cursor: pointer; margin-left: 4px; }
        .hp-btn-prev { background: #eef2f5; color: #5a6b7b; }
        .hp-btn-prev:hover { background: #e2e8ec; }
        .hp-btn-save { background: var(--eg-accent); color: #fff; }
        .hp-btn-save:hover { background: var(--eg-accent-d); }
        .hp-btn-save:disabled { opacity: .45; cursor: not-allowed; }
        .hp-empty { text-align: center; color: #aaa; padding: 22px; }

        .hp-pager { display: flex; align-items: center; justify-content: flex-end; gap: 6px; margin-top: 12px; flex-wrap: wrap; }
        .hp-pager button { border: 1px solid var(--eg-line); background: #fff; border-radius: 6px; padding: 4px 10px; font-size: 12.5px; cursor: pointer; }
        .hp-pager button:disabled { opacity: .4; cursor: not-allowed; }
        .hp-pager .cur { color: var(--eg-muted); font-size: 12.5px; margin: 0 6px; }

        /* 預覽 */
        .hp-preview { position: sticky; top: 12px; }
        .hp-preview-head { padding: 11px 16px; border-bottom: 1px solid var(--eg-line); font-weight: 700; color: var(--eg-dark); display: flex; align-items: center; gap: 8px; }
        .hp-prev-label { font-weight: 600; color: var(--eg-accent-d); font-size: 13px; }
        .hp-prev-open { margin-left: auto; font-size: 12px; }
        .hp-frame-wrap { position: relative; width: 100%; height: 540px; overflow: hidden; background: #f4f7f9; }
        #hp-prev-frame { position: absolute; top: 0; left: 0; width: 1440px; height: 1350px; transform-origin: top left; border: 0; background: #fff; }
        .hp-frame-empty { display: flex; align-items: center; justify-content: center; height: 100%; color: #b7c2cc; font-size: 13px; text-align: center; padding: 20px; }

        /* 角色說明 modal */
        #roleHelpModal .modal-dialog { width: 640px; max-width: 96%; }
        #roleHelpModal .modal-header { background: var(--eg-dark); color: #fff; border-radius: 6px 6px 0 0; }
        #roleHelpModal .modal-header .close { color: #fff; opacity: .9; }
        .rh-table { width: 100%; border-collapse: collapse; }
        .rh-table th { font-size: 12px; color: var(--eg-muted); text-align: center; padding: 10px 8px; border-bottom: 2px solid var(--eg-line); background: #f8f9fa; }
        .rh-table th:first-child { text-align: left; padding-left: 16px; }
        .rh-table td { padding: 10px 8px; border-bottom: 1px solid var(--eg-line); text-align: center; font-size: 13px; }
        .rh-table td:first-child { text-align: left; padding-left: 16px; font-weight: 600; color: var(--eg-dark); }
        .rh-table tr.is-mine { background: #f0faf7; }
        .rh-yes { color: var(--eg-accent-d); font-weight: 700; }
        .rh-no { color: #d7dde2; }
        .rh-mine-tag { font-size: 10px; background: var(--eg-accent); color: #fff; border-radius: 3px; padding: 1px 5px; margin-left: 6px; }
        .rh-sys-tag { font-size: 10px; background: #f0ad4e; color: #fff; border-radius: 3px; padding: 1px 5px; margin-left: 6px; }

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
        .eg-btn { border: none; border-radius: 7px; padding: 7px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .eg-btn-ghost { background: #eef2f5; color: #5a6b7b; }
    </style>
</head>
<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="eg-hp">

                    <!-- 標題列 -->
                    <div class="eg-head">
                        <div class="eg-head-left">
                            <h1>
                                <span class="eg-head-ico"><i class="fa fa-home"></i></span>
                                <span>首頁設定<small>設定各部門登入後預設首頁，並可為個別人員指定專屬首頁</small></span>
                            </h1>
                            <span class="eg-myrole">
                                <span class="eg-myrole-label">您的角色：</span>
                                <?php if ($effective_bootstrap_admin) : ?>
                                    <span class="rolepill rolepill-admin">管理員（系統初始）</span>
                                <?php elseif (!empty($my_roles)) : ?>
                                    <?php foreach ($my_roles as $rn) : ?>
                                        <span class="rolepill<?= $rn === '管理員' ? ' rolepill-admin' : '' ?>"><?= htmlspecialchars($rn) ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="rolepill rolepill-none">未指派角色</span>
                                <?php endif; ?>
                                <a href="javascript:;" class="eg-role-help" data-toggle="modal" data-target="#roleHelpModal" title="各角色權限說明">?</a>
                            </span>
                        </div>
                        <div class="eg-head-right">
                            <?php if ($IS_ADMIN) : ?>
                                <button type="button" class="eg-btn-perm" data-toggle="modal" data-target="#permModal" title="設定角色與權限"><i class="fa fa-key"></i> 權限設定</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="hp-layout">
                        <!-- 左：設定 -->
                        <div>
                            <!-- 全域預設首頁（未設定部門/個人時套用）-->
                            <div class="eg-card">
                                <div class="eg-card-head">
                                    <div class="ttl"><i class="fa fa-globe" style="color:#e67e22;"></i> 全域預設首頁</div>
                                </div>
                                <div class="eg-card-body">
                                    <p style="font-size:12px;color:var(--eg-muted);margin:0 0 10px;">
                                        <i class="fa fa-info-circle"></i> 當使用者<strong>個人</strong>與其<strong>部門</strong>都未設定首頁時，登入後套用此預設。若此處也未設定，則沿用系統原本的身分導向。
                                    </p>
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                        <select class="hp-sel" id="hp-default-sel" <?= $CAN_EDIT ? '' : 'disabled' ?>>
                                            <option value="">— 未設定（沿用系統原本身分導向）—</option>
                                            <?php foreach ($options as $o) : ?>
                                                <option value="<?= htmlspecialchars($o['path']) ?>" <?= ($hp_default === $o['path']) ? 'selected' : '' ?>><?= htmlspecialchars($o['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="hp-row-btn hp-btn-prev" id="hp-default-prev">預覽</button>
                                        <?php if ($CAN_EDIT) : ?>
                                            <button type="button" class="hp-row-btn hp-btn-save" id="hp-default-save" disabled>儲存</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="eg-card">
                                <div class="eg-card-head">
                                    <div class="hp-tabs">
                                        <button type="button" class="hp-tab active" data-tab="dept"><i class="fa fa-sitemap"></i> 部門首頁</button>
                                        <button type="button" class="hp-tab" data-tab="user"><i class="fa fa-user"></i> 個人首頁（覆寫）</button>
                                    </div>
                                    <div class="hp-tools">
                                        <div class="hp-search">
                                            <i class="fa fa-search"></i>
                                            <input type="text" id="hp-search" placeholder="搜尋名稱...">
                                        </div>
                                        <select class="hp-select-sm" id="hp-pagesize">
                                            <option value="5">每頁 5</option>
                                            <option value="10" selected>每頁 10</option>
                                            <option value="20">每頁 20</option>
                                            <option value="50">每頁 50</option>
                                        </select>
                                        <button type="button" class="eg-btn-csv" id="hp-csv"><i class="fa fa-download"></i> CSV 匯出</button>
                                    </div>
                                </div>
                                <div class="eg-card-body">
                                    <?php if (!$CAN_EDIT) : ?>
                                        <p style="font-size:12px;color:#e67e22;margin:0 0 10px;"><i class="fa fa-lock"></i> 您沒有編輯權限，以下為唯讀檢視。</p>
                                    <?php endif; ?>
                                    <div style="overflow-x:auto;">
                                        <table class="hp-table">
                                            <thead>
                                                <tr>
                                                    <th id="hp-col-name" style="width:38%;">部門</th>
                                                    <th>登入後首頁</th>
                                                    <th style="width:150px;text-align:right;">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody id="hp-tbody"></tbody>
                                        </table>
                                    </div>
                                    <div class="hp-pager" id="hp-pager"></div>
                                </div>
                            </div>
                        </div>

                        <!-- 右：預覽 -->
                        <div class="eg-card hp-preview">
                            <div class="hp-preview-head">
                                <i class="fa fa-eye"></i> 首頁預覽
                                <span class="hp-prev-label" id="hp-prev-label">—</span>
                                <a href="javascript:;" class="hp-prev-open" id="hp-prev-open" style="display:none;" title="開新分頁檢視實際頁面"><i class="fa fa-external-link"></i> 開啟</a>
                            </div>
                            <div class="hp-frame-wrap">
                                <div class="hp-frame-empty" id="hp-frame-empty">選擇任一列的首頁，即可在此即時預覽頁面樣式。</div>
                                <iframe id="hp-prev-frame" style="display:none;"></iframe>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <?php include '../partPage/footer.html' ?>
        </div>
    </div>

    <!-- 角色權限說明 modal -->
    <div class="modal fade" id="roleHelpModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-question-circle"></i> 首頁設定　各角色權限說明</h4>
                </div>
                <div class="modal-body">
                    <table class="rh-table">
                        <thead>
                            <tr>
                                <th>角色</th>
                                <?php foreach ($PAGE_FEATURES as $f) : ?><th><?= htmlspecialchars(str_replace('首頁設定', '', $f['label'])) ?></th><?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_roles)) : ?>
                                <tr><td colspan="<?= count($PAGE_FEATURES) + 1 ?>" style="text-align:center;color:#aaa;padding:24px;">尚無角色</td></tr>
                            <?php else : foreach ($all_roles as $r) :
                                $mine = in_array($r['role_name'], $my_roles, true) || ($effective_bootstrap_admin && $r['is_system']);
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
                        <i class="fa fa-info-circle"></i> ✓ = 該角色擁有此功能；「系統」角色(管理員)擁有全部權限。使用者最終權限為其所有角色的聯集。
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($IS_ADMIN) : ?>
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
                        <div class="perm-roles">
                            <div class="perm-roles-head">
                                <span>角色清單</span>
                                <button type="button" class="perm-add-btn" onclick="permAddRole()"><i class="fa fa-plus"></i> 新增</button>
                            </div>
                            <div class="perm-roles-list" id="perm-roles-list">
                                <div class="text-center text-muted" style="padding:20px;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>
                            </div>
                        </div>
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
                    <select id="pdo-transfer" class="hp-select-sm" style="width:100%;">
                        <option value="">直接移除此角色（這些人員將不再擁有此角色）</option>
                    </select>
                </div>
                <span class="pdo-label">請輸入大寫 <b style="color:#e74c3c;">Y</b> 以確認刪除</span>
                <input type="text" id="pdo-confirm-input" class="hp-select-sm" maxlength="1" autocomplete="off" placeholder="Y">
            </div>
            <div class="pdo-foot">
                <button type="button" class="eg-btn eg-btn-ghost" onclick="permDelCancel()">取消</button>
                <button type="button" class="eg-btn" id="pdo-confirm-btn" style="background:#e74c3c;color:#fff;" disabled onclick="permDelConfirm()"><i class="fa fa-trash"></i> 確認刪除</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/fastclick.js"></script>
    <script src="../../resource/js/nprogress.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <script>
        // === 資料（由後端注入） ===
        var HP_OPTIONS  = <?= json_encode($options, JSON_UNESCAPED_UNICODE) ?>;
        var HP_DEPTS    = <?= json_encode(array_map(function($d) use ($deptMap) {
                                return ['id'=>(int)$d['id'], 'name'=>$d['name'], 'path'=>getDeptPath($d['id'], $deptMap), 'home_page'=>$d['home_page']];
                            }, $departments), JSON_UNESCAPED_UNICODE) ?>;
        var HP_USERS    = <?= json_encode(array_map(function($u) {
                                return ['id'=>(int)$u['id'], 'name'=>$u['user_cname'], 'path'=>$u['department_name'], 'home_page'=>$u['home_page']];
                            }, $users), JSON_UNESCAPED_UNICODE) ?>;
        var CAN_EDIT    = <?= $CAN_EDIT ? 'true' : 'false' ?>;
        var HP_DEFAULT  = <?= json_encode($hp_default, JSON_UNESCAPED_UNICODE) ?>;
        var HP_API      = '../../src/store/_homePageSetting_API.php';
        var HP_FRAME_W  = 1440; // 預覽用邏輯桌面寬度，確保頁面以桌面版面渲染（月曆等才不會跑掉）

        var hpTab = 'dept';      // dept | user
        var hpPage = 1;
        var hpPageSize = 10;
        var hpSearch = '';

        function hpData() { return hpTab === 'dept' ? HP_DEPTS : HP_USERS; }

        function hpFiltered() {
            var q = hpSearch.trim().toLowerCase();
            var rows = hpData();
            if (!q) return rows;
            return rows.filter(function(r) {
                return (r.name || '').toLowerCase().indexOf(q) >= 0
                    || (r.path || '').toLowerCase().indexOf(q) >= 0;
            });
        }

        function hpOptionsHtml(selected) {
            var h = '<option value="">— 未設定（' + (hpTab === 'dept' ? '沿用系統預設' : '沿用部門設定') + '）—</option>';
            HP_OPTIONS.forEach(function(o) {
                var sel = (o.path === selected) ? ' selected' : '';
                h += '<option value="' + o.path + '"' + sel + '>' + $('<i>').text(o.label).html() + '</option>';
            });
            return h;
        }

        function hpRender() {
            var rows = hpFiltered();
            var total = rows.length;
            var pages = Math.max(1, Math.ceil(total / hpPageSize));
            if (hpPage > pages) hpPage = pages;
            var start = (hpPage - 1) * hpPageSize;
            var pageRows = rows.slice(start, start + hpPageSize);

            $('#hp-col-name').text(hpTab === 'dept' ? '部門' : '人員');

            var html = '';
            if (pageRows.length === 0) {
                html = '<tr><td colspan="3" class="hp-empty">查無資料</td></tr>';
            } else {
                pageRows.forEach(function(r) {
                    var idAttr = 'data-id="' + r.id + '"';
                    var nameCell = '<span class="hp-dept-name">' + $('<i>').text(r.name || ('#' + r.id)).html() + '</span>'
                                 + (r.path ? '<span class="hp-dept-path">' + $('<i>').text(r.path).html() + '</span>' : '');
                    var selDisabled = CAN_EDIT ? '' : ' disabled';
                    var selCell = '<select class="hp-sel" ' + idAttr + selDisabled + '>' + hpOptionsHtml(r.home_page) + '</select>';
                    var actCell = '<div style="text-align:right;white-space:nowrap;">'
                        + '<button type="button" class="hp-row-btn hp-btn-prev" ' + idAttr + ' data-act="prev">預覽</button>'
                        + (CAN_EDIT ? '<button type="button" class="hp-row-btn hp-btn-save" ' + idAttr + ' data-act="save" disabled>儲存</button>' : '')
                        + '</div>';
                    html += '<tr>' + '<td>' + nameCell + '</td><td>' + selCell + '</td><td>' + actCell + '</td></tr>';
                });
            }
            $('#hp-tbody').html(html);

            // 分頁
            var pager = '';
            pager += '<span class="cur">共 ' + total + ' 筆，第 ' + hpPage + ' / ' + pages + ' 頁</span>';
            pager += '<button ' + (hpPage <= 1 ? 'disabled' : '') + ' data-pg="first">«</button>';
            pager += '<button ' + (hpPage <= 1 ? 'disabled' : '') + ' data-pg="prev">‹</button>';
            pager += '<button ' + (hpPage >= pages ? 'disabled' : '') + ' data-pg="next">›</button>';
            pager += '<button ' + (hpPage >= pages ? 'disabled' : '') + ' data-pg="last">»</button>';
            $('#hp-pager').html(pager);
        }

        // 以固定邏輯寬度渲染，再等比縮放填滿預覽框；月曆等以桌面版面計算才不會跑版
        function hpSizeFrame() {
            var wrap = document.querySelector('.hp-frame-wrap');
            var f = document.getElementById('hp-prev-frame');
            if (!wrap || !f) return;
            var w = wrap.clientWidth, h = wrap.clientHeight;
            if (w <= 0) return;
            var scale = w / HP_FRAME_W;
            f.style.width = HP_FRAME_W + 'px';
            f.style.height = Math.ceil(h / scale) + 'px';
            f.style.transform = 'scale(' + scale + ')';
            f.style.transformOrigin = 'top left';
        }

        var _hpCurPath = '';
        function hpPreview(path, label) {
            var f = document.getElementById('hp-prev-frame');
            if (!path) {
                _hpCurPath = '';
                $(f).hide().attr('src', 'about:blank');
                $('#hp-prev-open').hide();
                $('#hp-frame-empty').show().text('此列尚未設定首頁。');
                $('#hp-prev-label').text('未設定');
                return;
            }
            _hpCurPath = path;
            $('#hp-frame-empty').hide();
            hpSizeFrame();
            // 載入完成後觸發 resize，讓月曆/圖表等依實際版面重算，避免縮圖跑版
            f.onload = function() {
                try {
                    var cw = f.contentWindow;
                    cw.dispatchEvent(new Event('resize'));
                    // 部分元件延遲初始化，稍後再補一次
                    setTimeout(function() { try { cw.dispatchEvent(new Event('resize')); } catch (e) {} }, 400);
                } catch (e) {}
            };
            $(f).show().attr('src', '../../' + path);
            $('#hp-prev-label').text(label || path);
            $('#hp-prev-open').show().off('click').on('click', function() { window.open('../../' + path, '_blank'); });
        }

        function hpSaveRow(id, path, $btn) {
            var action = (hpTab === 'dept') ? 'save_dept' : 'save_user';
            var data = { action: action, home_page: path };
            if (hpTab === 'dept') data.department_id = id; else data.user_id = id;
            $btn.prop('disabled', true).text('儲存中...');
            $.post(HP_API, data, function(res) {
                if (res && res.success) {
                    // 更新本地快取
                    var rows = hpData();
                    for (var i = 0; i < rows.length; i++) { if (rows[i].id == id) { rows[i].home_page = path || null; break; } }
                    $btn.text('已儲存').css('background', '#5aa469');
                    setTimeout(function() { $btn.text('儲存').css('background', '').prop('disabled', true); }, 1200);
                    $btn.closest('tr').find('.hp-sel').removeClass('dirty');
                } else {
                    alert('儲存失敗：' + (res && res.message ? res.message : ''));
                    $btn.prop('disabled', false).text('儲存');
                }
            }, 'json').fail(function() { alert('連線失敗'); $btn.prop('disabled', false).text('儲存'); });
        }

        function hpCsv() {
            var rows = hpFiltered();
            var head = (hpTab === 'dept' ? '部門' : '人員');
            var lines = ['﻿' + head + ',所屬,登入後首頁'];
            var labelByPath = {};
            HP_OPTIONS.forEach(function(o) { labelByPath[o.path] = o.label; });
            rows.forEach(function(r) {
                var hp = r.home_page ? (labelByPath[r.home_page] || r.home_page) : '(未設定)';
                function esc(s) { s = (s == null ? '' : String(s)); return '"' + s.replace(/"/g, '""') + '"'; }
                lines.push([esc(r.name), esc(r.path), esc(hp)].join(','));
            });
            var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = '首頁設定_' + head + '.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        }

        $(function() {
            hpRender();

            // 視窗尺寸改變時重新調整預覽縮放
            $(window).on('resize', function() { if (_hpCurPath) hpSizeFrame(); });

            // ── 全域預設首頁 ──
            $('#hp-default-sel').on('change', function() {
                $('#hp-default-save').prop('disabled', false);
                var path = $(this).val();
                hpPreview(path, $(this).find('option:selected').text());
            });
            $('#hp-default-prev').on('click', function() {
                var $s = $('#hp-default-sel');
                hpPreview($s.val(), $s.find('option:selected').text());
            });
            $('#hp-default-save').on('click', function() {
                var $btn = $(this), path = $('#hp-default-sel').val();
                $btn.prop('disabled', true).text('儲存中...');
                $.post(HP_API, { action: 'save_default', home_page: path }, function(res) {
                    if (res && res.success) {
                        HP_DEFAULT = path || null;
                        $btn.text('已儲存').css('background', '#5aa469');
                        setTimeout(function() { $btn.text('儲存').css('background', '').prop('disabled', true); }, 1200);
                    } else {
                        alert('儲存失敗：' + (res && res.message ? res.message : ''));
                        $btn.prop('disabled', false).text('儲存');
                    }
                }, 'json').fail(function() { alert('連線失敗'); $btn.prop('disabled', false).text('儲存'); });
            });

            $('.hp-tab').on('click', function() {
                $('.hp-tab').removeClass('active'); $(this).addClass('active');
                hpTab = $(this).data('tab'); hpPage = 1; hpRender();
            });
            $('#hp-search').on('input', function() { hpSearch = $(this).val(); hpPage = 1; hpRender(); });
            $('#hp-pagesize').on('change', function() { hpPageSize = parseInt($(this).val(), 10) || 10; hpPage = 1; hpRender(); });
            $('#hp-csv').on('click', hpCsv);

            $('#hp-pager').on('click', 'button', function() {
                var pg = $(this).data('pg');
                var pages = Math.max(1, Math.ceil(hpFiltered().length / hpPageSize));
                if (pg === 'first') hpPage = 1;
                else if (pg === 'prev') hpPage = Math.max(1, hpPage - 1);
                else if (pg === 'next') hpPage = Math.min(pages, hpPage + 1);
                else if (pg === 'last') hpPage = pages;
                hpRender();
            });

            // select 變更 → 標記 dirty、啟用儲存、即時預覽
            $('#hp-tbody').on('change', '.hp-sel', function() {
                var $sel = $(this);
                $sel.addClass('dirty');
                $sel.closest('tr').find('.hp-btn-save').prop('disabled', false);
                var path = $sel.val();
                var label = $sel.find('option:selected').text();
                hpPreview(path, label);
            });

            // 預覽按鈕
            $('#hp-tbody').on('click', '[data-act="prev"]', function() {
                var $sel = $(this).closest('tr').find('.hp-sel');
                hpPreview($sel.val(), $sel.find('option:selected').text());
            });

            // 儲存按鈕
            $('#hp-tbody').on('click', '[data-act="save"]', function() {
                var id = $(this).data('id');
                var $sel = $(this).closest('tr').find('.hp-sel');
                hpSaveRow(id, $sel.val(), $(this));
            });
        });
    </script>

    <?php if ($IS_ADMIN) : ?>
    <script>
        // === RBAC 權限設定（沿用公告/報價單同一套；module = homepage） ===
        var ROLES_API = '../../src/store/Roles_API.php';
        var HOMEPAGE_MODULE = 'homepage';
        var _permRoleId = null, _permRoleSystem = false;
        var _permRolesCache = [];
        var _pdoRoleId = null, _pdoUserIds = [];

        function permLoadRoles() {
            $('#perm-roles-list').html('<div class="text-center text-muted" style="padding:20px;font-size:12px;"><i class="fa fa-spinner fa-spin"></i></div>');
            $.get(ROLES_API, { action: 'get_roles', module: HOMEPAGE_MODULE }, function(res) {
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
            var name = prompt('請輸入角色名稱（例：首頁設定管理員）');
            if (!name || !name.trim()) return;
            $.post(ROLES_API, { action: 'save_role', role_name: name.trim(), module: HOMEPAGE_MODULE }, function(res) {
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
</html>
