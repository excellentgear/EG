<?php
// views/Sales/Sales_Track.php — 業務追蹤
session_start();
if (!isset($_SESSION['userName']) && !isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    header("Location:../../index.php"); exit;
}
function st_safe($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

$db2 = new DBConnection(); $pdo = $db2->getPDO();
$user_id = intval($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);

// ── 權限取得 ─────────────────────────────────────────────────────────────
$permission_code = null;
try {
    $sp = $pdo->prepare("SELECT smp.page_id, smp.group_id FROM system_module_pages smp WHERE :s LIKE CONCAT('%',smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url!='' LIMIT 1");
    $sp->execute([':s' => $_SERVER['PHP_SELF']]);
    $pi = $sp->fetch(PDO::FETCH_ASSOC);
    if ($pi) {
        $user_perms = [];
        $spp = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:u AND scope='page' AND module_code=:p");
        $spp->execute([':u' => $user_id, ':p' => $pi['page_id']]);
        $user_perms = $spp->fetchAll(PDO::FETCH_COLUMN);
        if (empty($user_perms) && !empty($pi['group_id'])) {
            $sg = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id=:g LIMIT 1");
            $sg->execute([':g' => $pi['group_id']]);
            $gc = $sg->fetchColumn();
            if ($gc) {
                $sgp = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:u AND scope='group' AND module_code=:m");
                $sgp->execute([':u' => $user_id, ':m' => $gc]);
                $user_perms = $sgp->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        if (!empty($user_perms)) {
            $chars = array_unique(array_merge(...array_map('str_split', $user_perms)));
            if (in_array('A', $chars)) $permission_code = 'A';
            else { sort($chars); $permission_code = implode('', $chars); }
        }
    }
    if (!$permission_code) {
        $sp2 = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:u AND module_code='sales_track' LIMIT 1");
        $sp2->execute([':u' => $user_id]);
        $p2 = $sp2->fetchColumn();
        if ($p2) {
            $c2 = array_unique(str_split($p2));
            if (in_array('A', $c2)) $permission_code = 'A';
            else { sort($c2); $permission_code = implode('', $c2); }
        }
    }
} catch(Exception $e){}
if (!$permission_code) $permission_code = 'R';

$can_create       = ($permission_code === 'A' || strpos($permission_code,'C') !== false);
$can_update       = ($permission_code === 'A' || strpos($permission_code,'U') !== false);
$can_delete       = ($permission_code === 'A' || strpos($permission_code,'D') !== false);
$can_settings_edit= ($permission_code === 'A' || $permission_code === 'CDRU');
// BOSS已閱：依系統設定的已閱使用者判斷
$_boss_review_uid = 0;
try {
    $_bsq = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='boss_review_user_id' LIMIT 1");
    $_bsq->execute();
    $_brv = $_bsq->fetchColumn();
    if ($_brv !== false && $_brv !== '') $_boss_review_uid = intval($_brv);
} catch(Exception $_e){}
$is_boss_role = ($_boss_review_uid > 0 && $user_id === $_boss_review_uid);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>業務待辦追蹤 (Sales Track)</title>
<link href="../../resource/css/bootstrap.css" rel="stylesheet">
<link href="../../resource/css/font-awesome.css" rel="stylesheet">
<link href="../../resource/css/custom.css" rel="stylesheet">
<link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
<style>
:root{--prim:#2A3F54;--acc:#1ABB9C;--bg:#F4F7FC;--card:#FFF;--txt:#495057;--bdr:#E6E9ED;}
body{background:var(--bg);font-family:"Segoe UI","Roboto","Helvetica Neue",Arial,sans-serif;color:var(--txt);}
.right_col{background:var(--bg)!important;}

/* ── 統計卡片 ── */
.stats-wrap{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:16px;align-items:stretch;}
.stat-card{background:var(--card);border-radius:8px;padding:14px 18px;box-shadow:0 2px 6px rgba(0,0,0,.07);cursor:pointer;border-left:5px solid transparent;position:relative;overflow:hidden;border:1px solid #f0f0f0;flex:1;min-width:160px;display:flex;flex-direction:column;justify-content:center;transition:all .25s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(0,0,0,.1);}
.stat-card.active{box-shadow:0 0 0 3px var(--prim);transform:scale(1.02);z-index:1;}
.card-all.active{box-shadow:0 0 0 3px #3498DB!important;}
.card-active.active{box-shadow:0 0 0 3px #F39C12!important;}
.card-done.active{box-shadow:0 0 0 3px #1ABB9C!important;}
.card-boss.active{box-shadow:0 0 0 3px #E74C3C!important;}
.stat-card .sv{font-size:28px;font-weight:800;color:var(--prim);margin-bottom:2px;}
.stat-card .sl{font-size:12px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.8px;}
.stat-card .si{position:absolute;right:10px;top:8px;font-size:44px;opacity:.12;}
.card-all  {border-left-color:#3498DB;background:linear-gradient(135deg,#fff 68%,rgba(52,152,219,.08) 100%);}
.card-all   .sv{color:#2980B9;}
.card-active{border-left-color:#F39C12;background:linear-gradient(135deg,#fff 68%,rgba(243,156,18,.08) 100%);}
.card-active .sv{color:#c87f0a;}
.card-done  {border-left-color:#1ABB9C;background:linear-gradient(135deg,#fff 68%,rgba(26,187,156,.08) 100%);}
.card-done   .sv{color:#16956e;}
.card-boss  {border-left-color:#E74C3C;background:linear-gradient(135deg,#fff 68%,rgba(231,76,60,.08) 100%);}
.card-boss   .sv{color:#c0392b;}
.stat-btn{flex:0 0 auto;width:140px;align-items:center;border-left:none;justify-content:center;}

/* ── 標籤篩選 ── */
.tag-filter-wrap{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;align-items:center;}
.tag-filter-wrap .tf-label{font-size:12px;color:#888;font-weight:600;margin-right:4px;}
.tag-chip{padding:3px 10px;border-radius:12px;font-size:12px;border:1px solid #ddd;cursor:pointer;background:#f7f9fb;transition:all .15s;user-select:none;}
.tag-chip.active{background:var(--prim);color:#fff;border-color:var(--prim);}

/* ── 額外篩選 ── */
.filter-row{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;align-items:center;}
.filter-row .fr-label{font-size:12px;color:#888;}
.filter-row select{font-size:12px;height:28px;border-radius:14px;border:1px solid #ddd;padding:0 10px;background:#fff;}
.filter-row .btn-clear{font-size:11px;padding:2px 10px;border-radius:12px;background:#fff5f5;color:#e74c3c;border:1px solid #e74c3c88;}

/* ── 表格 ── */
table.dataTable thead th{background:#F8F9FA;color:#555;font-weight:700;border-bottom:2px solid #E9ECEF;padding:9px 6px;font-size:12px;white-space:nowrap;}
table.dataTable tbody td{padding:6px 6px;vertical-align:middle;border-bottom:1px solid #F1F3F5;font-size:12px;}
table.dataTable tbody tr:hover{background:#FAFBFE!important;}

/* ── 狀態 Badge ── */
.badge-active{background:#FFF3CD;color:#856404;border:1px solid #FFECB5;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;}
.badge-done{background:#D1F2EB;color:#1a7860;border:1px solid #A3E4D7;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;}
.badge-boss{background:#FADBD8;color:#922B21;border:1px solid #F1948A;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;}

/* ── 標籤 chip（表格顯示用） ── */
.label-chip{display:inline-block;padding:1px 7px;border-radius:10px;background:#EBF5FB;border:1px solid #AED6F1;color:#1A5276;font-size:11px;margin:1px;}

/* ── Modal 標籤 chip（選取用） ── */
.modal-label-chip{display:inline-block;background:#e8f5f2;color:#1ABB9C;border:1px solid #a8dfd4;border-radius:12px;padding:4px 12px;font-size:12px;font-weight:600;margin:3px;cursor:pointer;user-select:none;transition:all .15s;}
.modal-label-chip:hover{background:#c8ece6;border-color:#7dcfc5;}
.modal-label-chip.active{background:#1ABB9C;color:#fff;border-color:#1ABB9C;}

/* ── 進度說明 ── */
.note-preview{font-size:11px;color:#555;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.note-meta{font-size:10px;color:#aaa;}

/* ── Modal 共用 ── */
.modal-header{background:linear-gradient(135deg,var(--prim) 0%,#1d3045 100%);color:#fff;border-radius:8px 8px 0 0;}
.modal-header .close{color:#fff;opacity:.8;}
.modal-header .close:hover{opacity:1;}

/* ── 設定 Modal 左側 Nav ── */
.st-nav-btn{display:block;width:100%;text-align:left;padding:10px 14px;border:none;background:transparent;font-size:13px;color:#555;cursor:pointer;border-left:3px solid transparent;transition:all .15s;}
.st-nav-btn:hover{background:#eef1f6;color:var(--prim);}
.st-nav-btn.active{background:#eef1f6;color:var(--prim);border-left-color:var(--acc);font-weight:700;}
.st-nav-btn i{margin-right:7px;width:14px;text-align:center;}

/* ── 建立/修改者資訊 ── */
.track-meta{font-size:10px;color:#aaa;line-height:1.5;}

/* ── toast ── */
#st-toast{position:fixed;bottom:20px;right:20px;z-index:9999;min-width:240px;display:none;padding:13px 18px;color:#fff;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.2);font-size:13px;}

/* ── 料號搜尋下拉 ── */
.st-dropdown{position:absolute;z-index:2000;background:#fff;border:1px solid #ccc;width:100%;max-height:200px;overflow-y:auto;display:none;border-top:none;border-radius:0 0 4px 4px;box-shadow:0 4px 8px rgba(0,0,0,.1);}
.st-dropdown .sdi{padding:6px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f5f5f5;}
.st-dropdown .sdi:hover{background:#f0f4fa;}

/* ── 備註 Modal ── */
.note-item{border:1px solid #eee;border-radius:6px;padding:10px 12px;margin-bottom:8px;background:#fafbfc;}
.note-item .ni-text{font-size:13px;color:#333;white-space:pre-wrap;word-break:break-word;}
.note-item .ni-meta{font-size:11px;color:#aaa;margin-top:5px;}

/* 歷史 */
.hist-item{border-bottom:1px solid #f0f0f0;padding:6px 2px;font-size:12px;}
.hist-item:last-child{border-bottom:none;}
.hist-item .ha{font-weight:700;}
.hist-item.insert .ha{color:#27ae60;}
.hist-item.update .ha{color:#2980b9;}
.hist-item.delete .ha{color:#e74c3c;}
.hist-date-grp{margin:10px 0 4px;padding:3px 10px;background:#eef2f8;border-radius:4px;font-size:11px;font-weight:700;color:#7fa8c9;letter-spacing:.6px;}

/* 通知面板 */
#st-notif-panel{position:fixed;bottom:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:6px;max-width:320px;pointer-events:none;}
.st-notif-card{pointer-events:auto;background:#fff;border:1px solid #ddd;border-radius:7px;padding:11px 14px;box-shadow:0 4px 16px rgba(0,0,0,.15);cursor:pointer;position:relative;animation:notifSlideIn .25s ease;}
@keyframes notifSlideIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}

/* 表格列 highlight（點擊通知後跳到該列） */
@keyframes trackHL{0%,10%{background:#FFFDE7!important;}85%{background:#FFFDE7!important;}100%{background:transparent;}}
.track-hl-row{animation:trackHL 3s ease;}

/* 篩選過渡 */
#track-table-wrap{transition:opacity .15s ease;}
</style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
<div class="">

<!-- ── 頁面標題 ── -->
<div class="page-title">
    <div class="title_left">
        <h3>業務待辦追蹤 <small>Sales Track</small>
            &nbsp;<span style="font-size:13px;font-weight:400;color:#888;">（權限：<strong style="color:var(--prim);"><?= st_safe($permission_code) ?></strong>）</span>
            <i class="fa fa-question-circle" onclick="$('#permHelpModal').modal('show')" title="權限說明" style="cursor:pointer;color:#bbb;font-size:14px;vertical-align:middle;margin-left:4px;"></i>
        </h3>
    </div>
    <div class="title_right text-right">
        <button class="btn btn-default btn-sm" onclick="openSettingsModal()" style="border-radius:16px;">
            <i class="fa fa-cog"></i> 基本設定
        </button>
    </div>
</div>
<div class="clearfix"></div>

<!-- ── 狀態卡片 ── -->
<div class="stats-wrap" id="statsWrap">
    <div class="stat-card card-all active" onclick="setFilter('all')">
        <i class="fa fa-list-alt si"></i>
        <div class="sv" id="cnt-all">0</div><div class="sl">全部</div>
    </div>
    <div class="stat-card card-active" onclick="setFilter('active')">
        <i class="fa fa-clock-o si"></i>
        <div class="sv" id="cnt-active">0</div><div class="sl">進行中</div>
    </div>
    <div class="stat-card card-done" onclick="setFilter('completed')">
        <i class="fa fa-check-circle si"></i>
        <div class="sv" id="cnt-done">0</div><div class="sl">完工</div>
    </div>
    <?php if ($is_boss_role || $can_settings_edit || $permission_code === 'A'): ?>
    <div class="stat-card card-boss" onclick="setFilter('boss_unread')" id="bossCard">
        <i class="fa fa-eye-slash si"></i>
        <div class="sv" id="cnt-boss">0</div><div class="sl">完工未閱</div>
    </div>
    <?php endif; ?>
    <?php if ($can_create): ?>
    <div class="stat-card stat-btn" style="background:#26B99A;color:#fff;" onclick="openTrackModal(0)">
        <div style="text-align:center;"><i class="fa fa-plus-circle" style="font-size:26px;display:block;margin-bottom:4px;"></i><div style="font-size:13px;font-weight:600;">新增追蹤</div></div>
    </div>
    <?php endif; ?>
    <div class="stat-card stat-btn" style="background:#34495E;color:#fff;" onclick="openHistoryModal(0)">
        <div style="text-align:center;"><i class="fa fa-history" style="font-size:24px;display:block;margin-bottom:4px;"></i><div style="font-size:13px;font-weight:600;">歷史紀錄</div></div>
    </div>
</div>

<!-- ── 標籤篩選 ── -->
<div class="tag-filter-wrap" id="tagFilterWrap" style="display:none;">
    <span class="tf-label"><i class="fa fa-tags"></i> 標籤篩選</span>
    <div id="tagChips"></div>
    <button class="btn btn-xs btn-link" onclick="clearTagFilter()" style="font-size:11px;color:#e74c3c;display:none;" id="clearTagBtn"><i class="fa fa-times"></i> 清除</button>
</div>

<!-- ── 附加篩選 ── -->
<div class="filter-row" id="filterRow">
    <span class="fr-label"><i class="fa fa-filter"></i> 篩選</span>
    <select id="fltSource" onchange="applyFilters()" style="min-width:120px;">
        <option value="">全部來源人</option>
    </select>
    <select id="fltAssignee" onchange="applyFilters()" style="min-width:120px;">
        <option value="">全部負責人</option>
    </select>
    <select id="fltCustomer" onchange="applyFilters()" style="min-width:140px;">
        <option value="">全部客戶</option>
    </select>
    <div style="position:relative;display:inline-block;">
        <input type="text" id="fltSearch" placeholder="全域搜尋（說明/備註/料號…）" autocomplete="off"
               style="font-size:12px;height:28px;border-radius:14px;border:1px solid #ddd;padding:0 28px 0 10px;background:#fff;width:220px;"
               ondblclick="clearGlobalSearch()">
        <i class="fa fa-search" style="position:absolute;right:9px;top:50%;transform:translateY(-50%);color:#bbb;font-size:12px;pointer-events:none;"></i>
    </div>
    <button class="btn-clear btn btn-xs" onclick="resetFilters()"><i class="fa fa-times"></i> 重置篩選</button>
</div>

<!-- ── 主表格 ── -->
<div id="track-table-wrap" style="background:#fff;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,.05);padding:10px;overflow-x:auto;">
<table id="trackTable" class="table table-striped" style="width:100%;min-width:900px;">
<thead><tr>
    <th style="width:100px;">追蹤編號</th>
    <th style="width:82px;">日期</th>
    <th style="width:80px;">來源人</th>
    <th style="width:80px;">負責業務</th>
    <th style="width:90px;">客戶</th>
    <th style="width:110px;">料號/圖號</th>
    <th style="min-width:110px;">說明</th>
    <th style="width:90px;">標籤</th>
    <th style="min-width:140px;">進度說明</th>
    <th style="width:70px;">狀態</th>
    <th style="width:120px;text-align:center;">操作</th>
</tr></thead>
<tbody id="trackTbody"></tbody>
</table>
<div id="pagination-container" style="min-height:24px;margin-top:8px;padding:4px 6px;"></div>
</div>

</div><!-- end right_col inner -->
</div><!-- end right_col -->
<?php include '../partPage/footer.html' ?>
</div></div>

<!-- ── Toast ── -->
<div id="st-toast"></div>
<!-- ── 通知浮動面板 ── -->
<div id="st-notif-panel"></div>

<!-- ══════════════════════════════════════════════════
     MODAL: 新增 / 修改 追蹤項目
══════════════════════════════════════════════════ -->
<div class="modal fade" id="trackModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header">
    <button type="button" class="close" data-dismiss="modal">&times;</button>
    <h4 class="modal-title" id="trackModalTitle"><i class="fa fa-plus-circle"></i> 新增追蹤項目</h4>
</div>
<div class="modal-body" style="padding:20px;background:#f9fafb;">
<input type="hidden" id="tm-track-id" value="0">
<div class="row">
    <!-- 日期 -->
    <div class="col-md-3 form-group">
        <label>日期 <span class="text-danger">*</span></label>
        <input type="date" class="form-control" id="tm-date">
    </div>
    <!-- 來源部門 -->
    <div class="col-md-3 form-group">
        <label>來源部門 <span class="text-danger">*</span></label>
        <select class="form-control" id="tm-source-dept" onchange="loadDeptUsers('tm-source-dept','tm-source-user')">
            <option value="">請選擇部門</option>
        </select>
    </div>
    <!-- 來源人員 -->
    <div class="col-md-3 form-group">
        <label>來源人員 <span class="text-danger">*</span></label>
        <select class="form-control" id="tm-source-user">
            <option value="">先選部門</option>
        </select>
    </div>
    <!-- 負責業務 -->
    <div class="col-md-3 form-group">
        <label>負責業務 <span class="text-danger">*</span></label>
        <select class="form-control" id="tm-assignee">
            <option value="">請選擇</option>
        </select>
    </div>
</div>
<div class="row">
    <!-- 客戶 -->
    <div class="col-md-4 form-group" style="position:relative;">
        <label>客戶 <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="tm-customer-text" placeholder="輸入客戶名稱搜尋（雙擊清除）" autocomplete="off" ondblclick="clearCustomer()">
        <div class="st-dropdown" id="tm-customer-dd"></div>
        <input type="hidden" id="tm-customer-id">
        <div id="tm-customer-info" style="display:none;margin-top:4px;padding:4px 8px;background:#f6fafd;border:1px solid #d0e8f5;border-radius:4px;font-size:11px;color:#555;line-height:1.7;"></div>
    </div>
    <!-- 料號 -->
    <div class="col-md-4 form-group" style="position:relative;">
        <label>料號/圖號（與非超正擇一）</label>
        <input type="text" class="form-control" id="tm-part-text" placeholder="輸入料號搜尋（至少2字，雙擊清除）" autocomplete="off"
               oninput="searchParts(this.value)" ondblclick="clearPart()">
        <div class="st-dropdown" id="tm-part-dd"></div>
        <input type="hidden" id="tm-part-id">
        <div id="tm-part-info" style="display:none;margin-top:4px;padding:4px 8px;background:#f6fafd;border:1px solid #d0e8f5;border-radius:4px;font-size:11px;color:#555;"></div>
    </div>
    <!-- 非超正料號 -->
    <div class="col-md-4 form-group">
        <label>非超正料號（與料號擇一）</label>
        <input type="text" class="form-control" id="tm-non-std" placeholder="非系統內料號（雙擊清除）" maxlength="100" oninput="syncPartMutex()" ondblclick="clearNonStd()">
    </div>
</div>
<!-- 標籤 -->
<div class="form-group">
    <label><i class="fa fa-tags" style="color:var(--acc);"></i> 標籤</label>
    <div id="tm-labels-wrap" style="display:flex;flex-wrap:wrap;gap:6px;padding:6px 0;"></div>
</div>
<!-- 說明 -->
<div class="form-group">
    <label>說明（非必填）</label>
    <textarea class="form-control" id="tm-description" rows="3" placeholder="請填寫追蹤說明…" maxlength="2000"></textarea>
</div>
<!-- 說明附圖（修改模式） -->
<div id="tm-imgs-wrap" style="display:none;margin-top:-6px;margin-bottom:14px;">
    <div style="font-size:12px;color:#888;margin-bottom:6px;"><i class="fa fa-image" style="color:var(--acc);"></i> 說明附圖</div>
    <div id="tm-imgs-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;min-height:10px;"></div>
    <label class="btn btn-xs btn-default" style="cursor:pointer;border-radius:12px;margin-bottom:0;">
        <i class="fa fa-upload"></i> 上傳圖片
        <input type="file" id="tm-img-upload" accept="image/*" style="display:none;" onchange="uploadTrackImage(this)">
    </label>
    <span style="font-size:10px;color:#ccc;margin-left:6px;">支援 JPG / PNG / GIF / WebP</span>
</div>
<!-- 完工提示（edit模式顯示） -->
<div id="tm-complete-hint" style="display:none;">
    <div class="alert alert-success" style="padding:8px 12px;font-size:12px;margin-bottom:0;">
        <i class="fa fa-check-circle"></i> 此項目已完工
        <span id="tm-complete-info" style="margin-left:8px;"></span>
    </div>
</div>
<!-- BOSS已閱提示（edit模式顯示） -->
<div id="tm-boss-hint" style="display:none;">
    <div class="alert alert-danger" style="padding:8px 12px;font-size:12px;margin-bottom:0;">
        <i class="fa fa-eye"></i> BOSS已閱：<span id="tm-boss-info"></span>
    </div>
</div>
</div><!-- modal-body -->
<div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <button type="button" class="btn btn-success btn-sm" id="tm-complete-btn" onclick="confirmComplete()" style="display:none;">
            <i class="fa fa-check"></i> 完工
        </button>
    </div>
    <div>
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <?php if ($can_create || $can_update): ?>
        <button type="button" class="btn btn-primary" onclick="saveTrack()"><i class="fa fa-save"></i> 儲存</button>
        <?php endif; ?>
    </div>
</div>
</div></div></div>

<!-- ══════════════════════════════════════════════════
     MODAL: 完工確認
══════════════════════════════════════════════════ -->
<div class="modal fade" id="completeModal" tabindex="-1" style="z-index:1060;">
<div class="modal-dialog modal-sm"><div class="modal-content">
<div class="modal-header" style="background:#27AE60;">
    <button class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
    <h4 class="modal-title" style="color:#fff;"><i class="fa fa-check"></i> 確認完工</h4>
</div>
<div class="modal-body" style="text-align:center;">
    <p style="font-size:13px;color:#555;">請輸入大寫 <strong style="color:#e74c3c;">OK</strong> 確認此項目完工</p>
    <input type="text" class="form-control" id="complete-input" placeholder="輸入 OK" style="text-align:center;font-size:16px;font-weight:700;letter-spacing:4px;" maxlength="5">
    <p style="font-size:11px;color:#aaa;margin-top:8px;">完工後無法撤銷</p>
</div>
<div class="modal-footer">
    <button class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
    <button class="btn btn-success btn-sm" onclick="doComplete()"><i class="fa fa-check"></i> 確認完工</button>
</div>
</div></div></div>

<!-- ══════════════════════════════════════════════════
     MODAL: 進度說明（備註）
══════════════════════════════════════════════════ -->
<div class="modal fade" id="notesModal" tabindex="-1">
<div class="modal-dialog" style="width:600px;max-width:95vw;"><div class="modal-content">
<div class="modal-header">
    <button class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
    <h4 class="modal-title"><i class="fa fa-comments"></i> 進度說明</h4>
</div>
<div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:16px;">
    <input type="hidden" id="nm-track-id">
    <div id="nm-notes-list"></div>
</div>
<div class="modal-footer" style="border-top:1px solid #eee;padding:12px 16px;">
    <?php if ($can_create || $can_update): ?>
    <div style="display:flex;gap:8px;align-items:flex-end;width:100%;">
        <textarea class="form-control" id="nm-new-note" rows="2" placeholder="輸入新進度說明…" style="flex:1;font-size:13px;resize:none;"></textarea>
        <button class="btn btn-primary btn-sm" onclick="addNote()" style="white-space:nowrap;"><i class="fa fa-send"></i> 送出</button>
    </div>
    <?php endif; ?>
</div>
</div></div></div>

<!-- ══════════════════════════════════════════════════
     MODAL: 基本設定
══════════════════════════════════════════════════ -->
<div class="modal fade" id="settingsModal" tabindex="-1">
<div class="modal-dialog" style="width:720px;max-width:95vw;"><div class="modal-content">
<div class="modal-header">
    <button class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
    <h4 class="modal-title"><i class="fa fa-cog"></i> 基本設定</h4>
</div>
<div class="modal-body" style="padding:0;min-height:420px;">
    <div style="display:flex;height:460px;">
        <!-- 左側 Nav -->
        <div style="width:160px;background:#f5f7fa;border-right:1px solid #e4e8ed;flex-shrink:0;padding-top:8px;">
            <ul style="list-style:none;margin:0;padding:0;">
                <li><button class="st-nav-btn active" onclick="loadSettingsTab('unit',this)"><i class="fa fa-cog"></i> 基本設定</button></li>
                <li><button class="st-nav-btn" onclick="loadSettingsTab('labels',this)"><i class="fa fa-tags"></i> 標籤分類</button></li>
            </ul>
        </div>
        <!-- 右側內容 -->
        <div style="flex:1;overflow-y:auto;padding:20px;" id="settings-content">
            <div class="text-center text-muted" style="margin-top:80px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
</div>
</div></div></div>

<!-- ══════════════════════════════════════════════════
     MODAL: 歷史紀錄
══════════════════════════════════════════════════ -->
<div class="modal fade" id="historyModal" tabindex="-1">
<div class="modal-dialog" style="width:760px;max-width:95vw;"><div class="modal-content">
<div class="modal-header">
    <button class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
    <h4 class="modal-title"><i class="fa fa-history"></i> 歷史紀錄</h4>
</div>
<div class="modal-body" style="padding:0;">
    <!-- 篩選列 -->
    <div style="background:#f5f7fa;border-bottom:1px solid #e4e8ed;padding:10px 16px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
        <div class="btn-group btn-group-xs" id="hist-period-btns">
            <button class="btn btn-primary" onclick="setHistPeriod('today',this)">今日</button>
            <button class="btn btn-default" onclick="setHistPeriod('week',this)">本周</button>
            <button class="btn btn-default" onclick="setHistPeriod('month',this)">本月</button>
            <button class="btn btn-default" onclick="setHistPeriod('custom',this)">自訂區間</button>
        </div>
        <div id="hist-custom-range" style="display:none;align-items:center;gap:4px;flex-wrap:wrap;">
            <input type="date" id="hist-date-from" class="form-control input-xs" style="width:130px;">
            <span style="color:#888;">～</span>
            <input type="date" id="hist-date-to" class="form-control input-xs" style="width:130px;">
            <button class="btn btn-xs btn-info" onclick="loadHistory()"><i class="fa fa-search"></i> 查詢</button>
        </div>
        <div style="margin-left:auto;display:flex;align-items:center;gap:12px;flex-shrink:0;">
            <span style="font-size:11px;color:#aaa;">動作篩選：</span>
            <label style="margin:0;font-size:12px;font-weight:400;cursor:pointer;color:#27ae60;"><input type="checkbox" id="hf-insert" checked onchange="loadHistory()"> 新增</label>
            <label style="margin:0;font-size:12px;font-weight:400;cursor:pointer;color:#2980b9;"><input type="checkbox" id="hf-update" checked onchange="loadHistory()"> 修改</label>
            <label style="margin:0;font-size:12px;font-weight:400;cursor:pointer;color:#e74c3c;"><input type="checkbox" id="hf-delete" checked onchange="loadHistory()"> 刪除</label>
        </div>
    </div>
    <!-- 結果列表 -->
    <div style="max-height:56vh;overflow-y:auto;padding:10px 16px;" id="history-content">
        <div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i></div>
    </div>
</div>
<div class="modal-footer">
    <span id="hist-count" style="font-size:12px;color:#aaa;margin-right:auto;"></span>
    <button class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
</div>
</div></div></div>

<!-- ══════════════════════════════════════════════════
     MODAL: 權限說明
══════════════════════════════════════════════════ -->
<div class="modal fade" id="permHelpModal" tabindex="-1">
<div class="modal-dialog" style="max-width:580px;"><div class="modal-content">
<div class="modal-header">
    <button class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
    <h4 class="modal-title"><i class="fa fa-question-circle"></i> 權限說明</h4>
</div>
<div class="modal-body" style="font-size:13px;">
<table class="table table-bordered table-condensed" style="font-size:12px;">
<thead><tr style="background:#f7f9fb;"><th style="width:140px;white-space:nowrap;">權限代碼</th><th>可執行操作</th></tr></thead>
<tbody>
<tr><td><strong>A</strong><br><span style="color:#aaa;font-size:11px;">超級使用者</span></td><td>所有操作：新增、修改、刪除追蹤項目；管理標籤；修改設定</td></tr>
<tr><td><strong>CDRU</strong><br><span style="color:#aaa;font-size:11px;">主管</span></td><td>新增、修改、刪除追蹤項目；管理標籤；修改設定</td></tr>
<tr><td><strong>CRU</strong><br><span style="color:#aaa;font-size:11px;">業務</span></td><td>新增、修改追蹤項目；<em>無刪除功能</em></td></tr>
<tr><td><strong>CR</strong><br><span style="color:#aaa;font-size:11px;">老闆</span></td><td>唯讀；可點選「BOSS已閱」標記已閱讀完工項目</td></tr>
<tr><td><strong>R</strong><br><span style="color:#aaa;font-size:11px;">唯讀</span></td><td>唯讀檢視</td></tr>
</tbody>
</table>
<p style="color:#888;font-size:11px;">* 刪除項目需輸入大寫 Y 確認；完工需輸入大寫 OK 確認</p>
</div>
<div class="modal-footer"><button class="btn btn-default btn-sm" data-dismiss="modal">關閉</button></div>
</div></div></div>

<!-- ══════════════════════════════════════════════════
     MODAL: 說明詳情
══════════════════════════════════════════════════ -->
<div class="modal fade" id="descDetailModal" tabindex="-1">
<div class="modal-dialog" style="width:600px;max-width:95vw;"><div class="modal-content">
<div class="modal-header">
    <button class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
    <h4 class="modal-title" id="desc-detail-title"><i class="fa fa-align-left"></i> 說明詳情</h4>
</div>
<div class="modal-body" style="max-height:70vh;overflow-y:auto;padding:18px;" id="desc-detail-body"></div>
<div class="modal-footer"><button class="btn btn-default btn-sm" data-dismiss="modal">關閉</button></div>
</div></div></div>

<!-- Scripts -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/jquery.dataTables.min.js"></script>
<script src="../../resource/js/dataTables.bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>

<script>
var API = '../../src/store/store_Sales_Track_API.php';
var CAN_CREATE  = <?= json_encode($can_create) ?>;
var CAN_UPDATE  = <?= json_encode($can_update) ?>;
var CAN_DELETE  = <?= json_encode($can_delete) ?>;
var IS_BOSS     = <?= json_encode($is_boss_role) ?>;
var PERM        = <?= json_encode($permission_code) ?>;
var MY_USER_ID  = <?= json_encode($user_id) ?>;

var allData      = [];   // current page rows only
var allDepts     = [];
var allSalesUsers= [];
var allLabels    = [];
var activeFilter = 'all';
var activeTagIds = [];
var deptUserCache= {};
var currentPage  = 1;
var lastStats    = {all:0, active:0, done:0, boss_unread:0};
var globalSearch = '';
var _searchTimer = null;

var PRESET_COLORS = ['#1ABB9C','#3498DB','#E74C3C','#F39C12','#9B59B6','#27AE60','#E67E22','#2980B9','#D35400','#16A085'];

function h2r(hex, a) {
    var h = (hex||'#1ABB9C').replace('#','');
    var r=parseInt(h.slice(0,2),16), g=parseInt(h.slice(2,4),16), b=parseInt(h.slice(4,6),16);
    return 'rgba('+r+','+g+','+b+','+a+')';
}

$(function() {
    loadAllDepts();
    loadSalesUsers();
    loadLabels();
    fetchTableData(1);

    // 客戶搜尋
    var custTimer;
    $('#tm-customer-text').on('input', function() {
        clearTimeout(custTimer);
        var v = $(this).val();
        if (v.length < 1) { $('#tm-customer-dd').hide(); return; }
        custTimer = setTimeout(function() {
            $.post(API, {action:'get_customers', keyword:v}, function(r) {
                if (!r.success || !r.data.length) { $('#tm-customer-dd').hide(); return; }
                var dd = $('#tm-customer-dd').empty();
                r.data.forEach(function(c) {
                    var addrShort = (c.customer_address||'').substring(0, 18);
                    var sub = '<div style="font-size:10px;color:#888;margin-top:1px;">ID：'+esc(c.customer_id)+(addrShort?' · '+esc(addrShort):'')+'</div>';
                    $('<div class="sdi">').html(esc(c.customer)+sub)
                        .data({id:c.customer_id, name:c.customer, addr:c.customer_address||''})
                        .on('click', function(){ var d=$(this).data(); selectCustomer(d.id, d.name, d.addr); })
                        .appendTo(dd);
                });
                dd.show();
            }, 'json');
        }, 300);
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#tm-customer-text,#tm-customer-dd').length) $('#tm-customer-dd').hide();
        if (!$(e.target).closest('#tm-part-text,#tm-part-dd').length) $('#tm-part-dd').hide();
    });

    // 完工確認 Enter
    $('#complete-input').on('keydown', function(e) {
        if (e.key==='Enter') doComplete();
    });

    // 全域搜尋
    $('#fltSearch').on('input', function(){
        clearTimeout(_searchTimer);
        var v = $(this).val();
        _searchTimer = setTimeout(function(){ globalSearch=v; fetchTableData(1); }, 400);
    });

    // 通知輪詢
    // 【停用 2026-07-08 by Claude】公告系統已上線，右下角業務追蹤通知面板改由公告系統統一負責，故停用此輪詢。
    // 如需恢復，取消下一行註解即可。
    // startNotifPolling();
    if('Notification' in window && Notification.permission==='default') Notification.requestPermission();
});

function esc(s){ return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function showToast(msg, ok) {
    var t=$('#st-toast');
    t.text(msg).css('background', ok?'#26B99A':'#c0392b').fadeIn(200);
    setTimeout(function(){ t.fadeOut(400); }, 3000);
}

// ─── 載入全部部門 ─────────────────────────────────────────
function loadAllDepts() {
    $.post(API, {action:'get_all_depts'}, function(r) {
        if (!r.success) return;
        allDepts = r.data;
        var sel = $('#tm-source-dept').empty().append('<option value="">請選擇部門</option>');
        r.data.forEach(function(d) { sel.append('<option value="'+d.id+'">'+esc(d.name)+'</option>'); });
    }, 'json');
}

function loadDeptUsers(deptSelId, userSelId) {
    var deptId = $('#'+deptSelId).val();
    var uSel = $('#'+userSelId).empty().append('<option value="">請選擇人員</option>');
    if (!deptId) return;
    if (deptUserCache[deptId]) { populateUsers(uSel, deptUserCache[deptId]); return; }
    $.post(API, {action:'get_dept_users', dept_id:deptId}, function(r) {
        if (r.success) { deptUserCache[deptId]=r.data; populateUsers(uSel, r.data); }
    }, 'json');
}
function populateUsers(sel, users) {
    users.forEach(function(u) {
        var pos = u.position_name ? ' ('+esc(u.position_name)+')' : '';
        sel.append('<option value="'+u.id+'">'+esc(u.user_cname)+pos+'</option>');
    });
}

// ─── 載入業務人員 ─────────────────────────────────────────
function loadSalesUsers() {
    $.post(API, {action:'get_sales_users'}, function(r) {
        if (!r.success) return;
        allSalesUsers = r.data;
        var sel = $('#tm-assignee').empty().append('<option value="">請選擇負責業務</option>');
        r.data.forEach(function(u) {
            sel.append('<option value="'+u.id+'">'+esc(u.user_cname)+'</option>');
        });
    }, 'json');
}

// ─── 載入標籤 ─────────────────────────────────────────────
function loadLabels() {
    $.post(API, {action:'get_labels'}, function(r) {
        if (!r.success) return;
        allLabels = r.data;
        renderTagChips();
        renderModalLabels();
    }, 'json');
}

function renderTagChips() {
    var wrap = $('#tagChips').empty();
    if (!allLabels.length) { $('#tagFilterWrap').hide(); return; }
    $('#tagFilterWrap').show();
    allLabels.forEach(function(l) {
        var act = activeTagIds.indexOf(l.label_id)!==-1;
        var c = l.color || '#1ABB9C';
        var style = act ?
            'background:'+c+';border-color:'+c+';color:#fff;' :
            'background:'+h2r(c,.1)+';border-color:'+h2r(c,.45)+';color:'+c+';';
        wrap.append('<span class="tag-chip'+(act?' active':'')+'" data-id="'+l.label_id+'" style="'+style+'" onclick="toggleTagFilter('+l.label_id+')">'+esc(l.label_name)+'</span>');
    });
}
function toggleTagFilter(lid) {
    var idx = activeTagIds.indexOf(lid);
    if (idx===-1) activeTagIds.push(lid); else activeTagIds.splice(idx,1);
    $('#clearTagBtn').toggle(activeTagIds.length>0);
    renderTagChips();
    fetchTableData(1);
}
function clearTagFilter() { activeTagIds=[]; renderTagChips(); $('#clearTagBtn').hide(); fetchTableData(1); }

function renderModalLabels() {
    var wrap = $('#tm-labels-wrap').empty();
    var selected = [];
    try { selected = JSON.parse($('#tm-labels-json-hidden').val()||'[]'); } catch(e) {}
    if (!allLabels.length) { wrap.append('<span style="color:#bbb;font-size:12px;">（尚無標籤，請先至基本設定新增）</span>'); return; }
    allLabels.forEach(function(l) {
        var isActive = selected.indexOf(l.label_id) !== -1;
        var c = l.color || '#1ABB9C';
        var style = isActive ?
            'background:'+c+';border-color:'+c+';color:#fff;' :
            'background:'+h2r(c,.1)+';border-color:'+h2r(c,.45)+';color:'+c+';';
        wrap.append('<span class="modal-label-chip'+(isActive?' active':'')+'" data-id="'+l.label_id+'" style="'+style+'" onclick="toggleModalLabel(this,'+l.label_id+',\''+esc(c)+'\')">'+esc(l.label_name)+'</span>');
    });
    if (!$('#tm-labels-json-hidden').length) $('<input type="hidden" id="tm-labels-json-hidden">').appendTo('body');
}
function toggleModalLabel(el, lid, color) {
    var chip = $(el);
    var c = color || '#1ABB9C';
    var selected = [];
    try { selected = JSON.parse($('#tm-labels-json-hidden').val()||'[]'); } catch(e) {}
    var idx = selected.indexOf(lid);
    if (idx===-1) {
        selected.push(lid);
        chip.addClass('active').attr('style','background:'+c+';border-color:'+c+';color:#fff;');
    } else {
        selected.splice(idx,1);
        chip.removeClass('active').attr('style','background:'+h2r(c,.1)+';border-color:'+h2r(c,.45)+';color:'+c+';');
    }
    $('#tm-labels-json-hidden').val(JSON.stringify(selected));
}

// ─── 主要資料載入（分頁） ─────────────────────────────────
function fetchTableData(page, callback) {
    currentPage = page;
    var wrap = $('#track-table-wrap');
    wrap.css('opacity', '0.35');
    $.post(API, {
        action: 'load_page_data',
        page: page,
        status_filter: activeFilter,
        source_user_id: $('#fltSource').val() || 0,
        assignee_id: $('#fltAssignee').val() || 0,
        customer_id: $('#fltCustomer').val() || '',
        label_ids: JSON.stringify(activeTagIds),
        search_text: globalSearch
    }, function(r) {
        if (!r.success) { showToast('載入失敗：'+(r.message||''), false); wrap.animate({opacity:1}, 150); return; }
        allData    = r.rows;
        lastStats  = r.stats;
        updateStats();
        buildFilterDropdowns(r.dropdown_options);
        renderPageRows(r.rows);
        renderPagination(r.page, r.total_pages, r.total);
        wrap.animate({opacity:1}, 150);
        if (callback) callback();
    }, 'json');
}

function loadList() { fetchTableData(currentPage); }

// ─── 快速篩選（連點欄位） ─────────────────────────────────
function quickFilter(type, value) {
    if (type === 'source')   { $('#fltSource').val(value); }
    else if (type === 'assignee') { $('#fltAssignee').val(value); }
    else if (type === 'customer') { $('#fltCustomer').val(value); }
    else if (type === 'search')   { globalSearch=value; $('#fltSearch').val(value); }
    fetchTableData(1);
}
function clearGlobalSearch() {
    if (!$('#fltSearch').val()) return;
    globalSearch=''; $('#fltSearch').val('');
    fetchTableData(1);
}

function renderPagination(page, totalPages, total) {
    var limit = 8;
    var start = (page - 1) * limit + 1;
    var end   = Math.min(page * limit, total);
    var c = $('#pagination-container');
    if (total === 0) { c.empty(); return; }
    var prev = page <= 1
        ? '<button class="btn btn-default btn-xs" disabled><i class="fa fa-chevron-left"></i></button>'
        : '<button class="btn btn-default btn-xs" onclick="fetchTableData('+(page-1)+')"><i class="fa fa-chevron-left"></i></button>';
    var next = page >= totalPages
        ? '<button class="btn btn-default btn-xs" disabled><i class="fa fa-chevron-right"></i></button>'
        : '<button class="btn btn-default btn-xs" onclick="fetchTableData('+(page+1)+')"><i class="fa fa-chevron-right"></i></button>';
    c.html('<div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;">'
        + '<small style="color:#888;white-space:nowrap;">'+start+'–'+end+' / '+total+' 筆</small>'
        + prev
        + '<span style="font-size:12px;color:#555;">'+page+' / '+totalPages+'</span>'
        + next
        + '</div>');
}

function updateStats() {
    $('#cnt-all').text(lastStats.all);
    $('#cnt-active').text(lastStats.active);
    $('#cnt-done').text(lastStats.done);
    $('#cnt-boss').text(lastStats.boss_unread);
    $('.stat-card').removeClass('active');
    if (activeFilter==='all')              $('.card-all').addClass('active');
    else if(activeFilter==='active')       $('.card-active').addClass('active');
    else if(activeFilter==='completed')    $('.card-done').addClass('active');
    else if(activeFilter==='boss_unread')  $('.card-boss').addClass('active');
}

function setFilter(f) {
    activeFilter = f;
    fetchTableData(1);
}

function buildFilterDropdowns(opts) {
    if (!opts) return;
    var src  = $('#fltSource').val();
    var asgn = $('#fltAssignee').val();
    var cust = $('#fltCustomer').val();
    var s = $('#fltSource').empty().append('<option value="">全部來源人</option>');
    (opts.sources||[]).forEach(function(o){ s.append('<option value="'+o.id+'">'+esc(o.name||o.id)+'</option>'); });
    if (src) s.val(src);
    var a = $('#fltAssignee').empty().append('<option value="">全部負責人</option>');
    (opts.assignees||[]).forEach(function(o){ a.append('<option value="'+o.id+'">'+esc(o.name||o.id)+'</option>'); });
    if (asgn) a.val(asgn);
    var cu = $('#fltCustomer').empty().append('<option value="">全部客戶</option>');
    (opts.customers||[]).forEach(function(o){ cu.append('<option value="'+o.id+'">'+esc(o.name||o.id)+'</option>'); });
    if (cust) cu.val(cust);
}

function applyFilters() { fetchTableData(1); }
function resetFilters() {
    $('#fltSource,#fltAssignee,#fltCustomer').val('');
    globalSearch=''; $('#fltSearch').val('');
    activeTagIds=[]; renderTagChips(); $('#clearTagBtn').hide();
    activeFilter='all'; updateStats();
    fetchTableData(1);
}

// ─── 渲染表格（當頁資料） ─────────────────────────────────
function renderPageRows(data) {
    var tbody = $('#trackTbody').empty();
    data.forEach(function(row) {
        // 追蹤編號
        var codeHtml = row.track_code ?
            '<span style="font-family:monospace;font-size:11px;color:#555;letter-spacing:.5px;">'+esc(row.track_code)+'</span>' :
            '<span style="color:#ccc;font-size:11px;">—</span>';

        // 標籤 chips（使用標籤顏色）
        var labelHtml = '';
        (row.labels||[]).forEach(function(l) {
            var c = l.color || '#1ABB9C';
            labelHtml += '<span class="label-chip" style="background:'+h2r(c,.12)+';border-color:'+h2r(c,.4)+';color:'+c+';">'+esc(l.label_name)+'</span>';
        });

        // 狀態
        var statusHtml = '';
        if (row.status==='active') statusHtml = '<span class="badge-active">進行中</span>';
        else {
            statusHtml = '<span class="badge-done">完工</span>';
            if (row.boss_reviewed==1) statusHtml += '<br><span class="badge-boss" style="font-size:10px;">已閱</span>';
            else                       statusHtml += '<br><span style="font-size:10px;color:#aaa;">未閱</span>';
        }

        // 操作按鈕
        var actHtml = '';
        if (CAN_UPDATE || CAN_CREATE) {
            actHtml += '<button class="btn btn-xs btn-info" onclick="openTrackModal('+row.track_id+')" title="修改"><i class="fa fa-pencil"></i></button> ';
        }
        if (CAN_DELETE) {
            actHtml += '<button class="btn btn-xs btn-danger" onclick="deleteTrack('+row.track_id+')" title="刪除"><i class="fa fa-trash"></i></button> ';
        }
        if (IS_BOSS && row.status==='completed') {
            if (row.boss_reviewed==0) {
                actHtml += '<button class="btn btn-xs btn-warning" onclick="bossReview('+row.track_id+')" title="BOSS已閱"><i class="fa fa-eye"></i> 已閱</button>';
            } else {
                var reviewedAt = (row.boss_reviewed_at||'').substring(0,16);
                actHtml += '<div style="font-size:10px;color:#1ABB9C;white-space:nowrap;"><i class="fa fa-eye"></i> 已閱<br><span style="color:#bbb;">'+esc(reviewedAt)+'</span></div>';
            }
        }

        // 來源人
        var srcHtml = esc(row.source_user_name||'');
        if (row.source_dept_name) srcHtml = '<small style="color:#aaa;">'+esc(row.source_dept_name)+'</small><br>'+srcHtml;

        // 負責業務（不顯示職稱）
        var asnHtml = esc(row.assignee_name||'');

        // 料號
        var partHtml = row.d_setting_id ? esc(row.part_no||row.d_setting_id) : ('<span style="color:#888;font-style:italic;">'+esc(row.non_std_part||'')+'</span>');
        if (row.d_setting_id && row.part_spec) partHtml += '<div style="font-size:10px;color:#aaa;margin-top:1px;">'+esc(row.part_spec)+'</div>';

        // 說明 + 說明附圖
        var DESC_LIMIT = 55;
        var descHtml = '';
        if (row.description) {
            var descTrunc = row.description.length > DESC_LIMIT;
            var descText  = descTrunc ? row.description.substring(0, DESC_LIMIT) : row.description;
            descHtml += '<div style="font-size:12px;color:#444;margin-bottom:3px;">'
                + esc(descText)
                + (descTrunc ? '<a href="#" onclick="showDescDetail('+row.track_id+',event)" style="color:#3498DB;font-size:11px;margin-left:2px;" title="查看完整說明">…更多</a>' : '')
                + '</div>';
        }
        if (row.track_images && row.track_images.length) {
            var imgShow = row.track_images.slice(0, 2);
            var imgMore = row.track_images.length - 2;
            descHtml += '<div style="display:flex;flex-wrap:wrap;gap:3px;align-items:center;">';
            imgShow.forEach(function(img) {
                descHtml += '<a href="'+esc(img.url)+'" target="_blank" style="display:inline-block;"><img src="'+esc(img.url)+'" style="max-height:64px;max-width:120px;width:auto;height:auto;border-radius:3px;border:1px solid #ddd;display:block;" onerror="this.closest(\'a\').style.display=\'none\'"></a>';
            });
            if (imgMore > 0) {
                descHtml += '<a href="#" onclick="showDescDetail('+row.track_id+',event)" style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;background:#f0f4f8;border:1px solid #ccd;border-radius:3px;font-size:11px;color:#555;text-decoration:none;font-weight:600;">+'+imgMore+'</a>';
            }
            descHtml += '</div>';
        }

        // 進度說明 + 備註附圖
        var noteHtml = '';
        if (row.latest_note) {
            var nPerson = row.latest_note.updated_at ? (row.latest_note.updated_by_name||'') : (row.latest_note.created_by_name||'');
            var nDate   = (row.latest_note.updated_at || row.latest_note.created_at || '').substring(0,10);
            noteHtml = '<div class="note-preview">'+esc(row.latest_note.note_text)+'</div>';
            noteHtml += '<div class="note-meta">'+esc(nDate)+(nPerson?' &nbsp;'+esc(nPerson):'')+'</div>';
            if (row.latest_note.images && row.latest_note.images.length) {
                noteHtml += '<div style="display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;">';
                row.latest_note.images.forEach(function(img) {
                    noteHtml += '<a href="'+esc(img.url)+'" target="_blank" style="display:inline-block;"><img src="'+esc(img.url)+'" style="max-height:64px;max-width:120px;width:auto;height:auto;border-radius:3px;border:1px solid #ddd;display:block;" onerror="this.closest(\'a\').style.display=\'none\'"></a>';
                });
                noteHtml += '</div>';
            }
        }
        noteHtml += '<button class="btn btn-xs btn-link" style="padding:0;font-size:11px;" onclick="openNotesModal('+row.track_id+')"><i class="fa fa-comments"></i> 查看/新增</button>';

        var srcUid  = row.source_user_id || '';
        var asnId   = row.assignee_id    || '';
        var custId2 = (row.customer_id   || '').replace(/'/g,"\\'");
        var partSrch = (row.d_setting_id ? (row.part_no || String(row.d_setting_id)) : (row.non_std_part || '')).replace(/'/g,"\\'");
        $('<tr>').attr('data-track-id', row.track_id).html(
            '<td>'+codeHtml+'</td>'+
            '<td style="white-space:nowrap;">'+esc(row.track_date)+'</td>'+
            '<td style="cursor:default;" ondblclick="quickFilter(\'source\',\''+srcUid+'\');" title="連點可篩選此來源人">'+srcHtml+'</td>'+
            '<td style="cursor:default;" ondblclick="quickFilter(\'assignee\',\''+asnId+'\');" title="連點可篩選此負責人">'+asnHtml+'</td>'+
            '<td style="cursor:default;" ondblclick="quickFilter(\'customer\',\''+custId2+'\');" title="連點可篩選此客戶">'+esc(row.customer_name||row.customer_id)+'</td>'+
            '<td style="white-space:nowrap;cursor:default;" ondblclick="quickFilter(\'search\',\''+partSrch+'\');" title="連點可全域搜尋此料號">'+partHtml+'</td>'+
            '<td style="min-width:100px;">'+descHtml+'</td>'+
            '<td>'+labelHtml+'</td>'+
            '<td style="min-width:120px;">'+noteHtml+'</td>'+
            '<td>'+statusHtml+'</td>'+
            '<td style="white-space:nowrap;">'+actHtml+'</td>'
        ).appendTo(tbody);
    });
}

// ─── 新增 / 修改 Modal ───────────────────────────────────
function openTrackModal(trackId) {
    $('#tm-track-id').val(trackId);
    $('#tm-date').val(new Date().toISOString().split('T')[0]);
    $('#tm-source-dept').val('');
    $('#tm-source-user').empty().append('<option value="">先選部門</option>');
    $('#tm-customer-text').val(''); $('#tm-customer-id').val('');
    $('#tm-customer-info').hide().empty();
    $('#tm-part-text').val('').prop('disabled', false).css({background:'',color:''});
    $('#tm-part-id').val('');
    $('#tm-part-info').hide().empty();
    $('#tm-non-std').val('').prop('disabled', false).css({background:'',color:''});
    $('#tm-description').val('');
    $('#tm-labels-json-hidden').val('[]');
    $('#tm-complete-hint,#tm-boss-hint').hide();
    $('#tm-complete-btn').hide();

    $('#tm-imgs-wrap').hide();
    $('#tm-imgs-list').empty();
    renderModalLabels();

    if (trackId === 0) {
        $('#trackModalTitle').html('<i class="fa fa-plus-circle"></i> 新增追蹤項目');
        $('#trackModal').modal('show');
    } else {
        $('#trackModalTitle').html('<i class="fa fa-pencil"></i> 修改追蹤項目');
        // 載入資料
        var row = allData.find(function(d){ return d.track_id == trackId; });
        if (!row) { showToast('找不到資料', false); return; }

        $('#tm-date').val(row.track_date);
        // 部門
        $('#tm-source-dept').val(row.source_dept_id);
        // 先載入部門人員，再設定人員
        $.post(API, {action:'get_dept_users', dept_id:row.source_dept_id}, function(r) {
            var uSel = $('#tm-source-user').empty().append('<option value="">請選擇人員</option>');
            if (r.success) {
                deptUserCache[row.source_dept_id] = r.data;
                populateUsers(uSel, r.data);
                uSel.val(row.source_user_id);
            }
        }, 'json');

        $('#tm-assignee').val(row.assignee_id);
        $('#tm-customer-text').val(row.customer_name||row.customer_id);
        $('#tm-customer-id').val(row.customer_id);
        if (row.customer_id) {
            var cInfo = '<i class="fa fa-id-card-o"></i> <strong>'+esc(row.customer_id)+'</strong>';
            if (row.customer_address) cInfo += '&nbsp;&nbsp;<i class="fa fa-map-marker"></i> '+esc(row.customer_address);
            $('#tm-customer-info').html(cInfo).show();
        }
        if (row.d_setting_id) {
            $('#tm-part-text').val(row.part_no||row.d_setting_id);
            $('#tm-part-id').val(row.d_setting_id);
            if (row.part_spec) $('#tm-part-info').html('<i class="fa fa-info-circle"></i> 規格：<strong>'+esc(row.part_spec)+'</strong>').show();
        } else {
            $('#tm-non-std').val(row.non_std_part||'');
        }
        syncPartMutex();
        $('#tm-description').val(row.description||'');

        // 標籤
        var selIds = (row.labels||[]).map(function(l){ return l.label_id; });
        $('#tm-labels-json-hidden').val(JSON.stringify(selIds));
        renderModalLabels();

        // 完工狀態
        if (row.status==='completed') {
            var cInfo = row.completed_by_name ? '完工人：'+row.completed_by_name+' '+row.completed_at : row.completed_at;
            $('#tm-complete-info').text(cInfo);
            $('#tm-complete-hint').show();
            if (row.boss_reviewed==1) {
                $('#tm-boss-info').text('已閱覽 — '+(row.boss_reviewed_by_name||'')+' '+row.boss_reviewed_at);
                $('#tm-boss-hint').show();
            }
        } else {
            // A/CDRU（有 U 且有 D）→ 可完工任何案子
            // CRU  （有 U 無 D）→ 只能完工自己負責的案子
            if (CAN_UPDATE && (CAN_DELETE || row.assignee_id == MY_USER_ID)) $('#tm-complete-btn').show();
        }

        // 顯示說明附圖區
        $('#tm-imgs-wrap').show();
        loadTrackImages(trackId);

        $('#trackModal').modal('show');
    }
}

function selectCustomer(id, name, addr) {
    $('#tm-customer-id').val(id);
    $('#tm-customer-text').val(name);
    $('#tm-customer-dd').hide();
    var info = '<i class="fa fa-id-card-o"></i> <strong>'+esc(id)+'</strong>';
    if (addr) info += '&nbsp;&nbsp;<i class="fa fa-map-marker"></i> '+esc(addr);
    $('#tm-customer-info').html(info).show();
}
function clearCustomer() {
    $('#tm-customer-text').val('');
    $('#tm-customer-id').val('');
    $('#tm-customer-info').hide().empty();
    $('#tm-customer-dd').hide();
}
function clearNonStd() {
    $('#tm-non-std').val('');
    syncPartMutex();
}

function searchParts(kw) {
    if (kw.length < 2) { $('#tm-part-dd').hide(); return; }
    $.post(API, {action:'get_parts', keyword:kw}, function(r) {
        if (!r.success || !r.data.length) { $('#tm-part-dd').hide(); return; }
        var dd = $('#tm-part-dd').empty();
        r.data.forEach(function(p) {
            var meta = [];
            if (p.customer_name) meta.push('<span style="color:#1ABB9C;">'+esc(p.customer_name)+'</span>');
            if (p.Spec_No)        meta.push('<span style="color:#888;">'+esc(p.Spec_No)+'</span>');
            var inner = esc(p.D_Setting_Id)+(meta.length?'<div style="font-size:11px;margin-top:1px;">'+meta.join(' · ')+'</div>':'');
            $('<div class="sdi">').html(inner)
                .data({id:p.d_id, label:p.D_Setting_Id, spec:p.Spec_No||''})
                .on('click', function(){ var d=$(this).data(); selectPart(d.id, d.label, d.spec); })
                .appendTo(dd);
        });
        dd.show();
    }, 'json');
}
function syncPartMutex() {
    var hasPart   = !!$('#tm-part-id').val();
    var hasNonStd = $('#tm-non-std').val().trim() !== '';
    if (hasPart) {
        $('#tm-non-std').val('').prop('disabled', true).css({background:'#f0f0f0',color:'#aaa'});
        $('#tm-part-text').prop('disabled', false).css({background:'',color:''});
    } else if (hasNonStd) {
        $('#tm-part-text').prop('disabled', true).css({background:'#f0f0f0',color:'#aaa'});
        $('#tm-part-dd').hide();
        $('#tm-non-std').prop('disabled', false).css({background:'',color:''});
    } else {
        $('#tm-part-text').prop('disabled', false).css({background:'',color:''});
        $('#tm-non-std').prop('disabled', false).css({background:'',color:''});
    }
}
function selectPart(id, label, spec) {
    $('#tm-part-id').val(id);
    $('#tm-part-text').val(label);
    $('#tm-part-dd').hide();
    if (spec) $('#tm-part-info').html('<i class="fa fa-info-circle"></i> 規格：<strong>'+esc(spec)+'</strong>').show();
    else       $('#tm-part-info').hide().empty();
    syncPartMutex();
}
function clearPart() {
    $('#tm-part-id').val('');
    $('#tm-part-text').val('');
    $('#tm-part-info').hide().empty();
    syncPartMutex();
}

// ─── 儲存追蹤 ────────────────────────────────────────────
function saveTrack() {
    var date      = $('#tm-date').val();
    var srcDept   = $('#tm-source-dept').val();
    var srcUser   = $('#tm-source-user').val();
    var assignee  = $('#tm-assignee').val();
    var custId    = $('#tm-customer-id').val();
    var partId    = $('#tm-part-id').val();
    var nonStd    = $('#tm-non-std').val().trim();
    var labelIds  = [];
    try { labelIds = JSON.parse($('#tm-labels-json-hidden').val()||'[]'); } catch(e) {}

    if (!date)     { showToast('請選擇日期', false); return; }
    if (!srcDept)  { showToast('請選擇來源部門', false); return; }
    if (!srcUser)  { showToast('請選擇來源人員', false); return; }
    if (!assignee) { showToast('請選擇負責業務', false); return; }
    if (!custId)   { showToast('請選擇客戶', false); return; }
    if (!partId && !nonStd) { showToast('料號與非超正料號至少填寫一項', false); return; }

    $.post(API, {
        action: 'save_track',
        track_id:      $('#tm-track-id').val(),
        track_date:    date,
        source_dept_id: srcDept,
        source_user_id: srcUser,
        assignee_id:   assignee,
        customer_id:   custId,
        d_setting_id:  partId||'',
        non_std_part:  nonStd,
        description:   $('#tm-description').val(),
        label_ids:     JSON.stringify(labelIds)
    }, function(r) {
        if (r.success) {
            showToast('儲存成功', true);
            var wasNew = ($('#tm-track-id').val() == '0');
            $('#trackModal').modal('hide');
            loadLabels();
            fetchTableData(wasNew ? 1 : currentPage);
        } else {
            showToast('儲存失敗：'+(r.message||''), false);
        }
    }, 'json');
}

// ─── 刪除 ────────────────────────────────────────────────
function deleteTrack(id) {
    var v = prompt('確認刪除此追蹤項目？\n\n請輸入大寫 Y 確認：');
    if (v === null) return;
    if (v !== 'Y') { showToast('輸入不正確，取消刪除', false); return; }
    $.post(API, {action:'delete_track', track_id:id}, function(r) {
        if (r.success) { showToast('已刪除', true); fetchTableData(currentPage); }
        else showToast('刪除失敗：'+(r.message||''), false);
    }, 'json');
}

// ─── 完工 ────────────────────────────────────────────────
var pendingCompleteId = 0;
function confirmComplete() {
    pendingCompleteId = parseInt($('#tm-track-id').val());
    $('#complete-input').val('');
    $('#completeModal').modal('show');
}
function doComplete() {
    if ($('#complete-input').val() !== 'OK') { showToast('請輸入大寫 OK', false); return; }
    $('#completeModal').modal('hide');
    $.post(API, {action:'complete_track', track_id:pendingCompleteId}, function(r) {
        if (r.success) {
            showToast('已標記完工', true);
            $('#trackModal').modal('hide');
            fetchTableData(currentPage);
        } else showToast('操作失敗：'+(r.message||''), false);
    }, 'json');
}

// ─── BOSS已閱 ────────────────────────────────────────────
function bossReview(id) {
    if (!confirm('確認 BOSS 已閱此完工項目？')) return;
    $.post(API, {action:'boss_review', track_id:id}, function(r) {
        if (r.success) {
            showToast('已標記 BOSS已閱', true);
            // 立即更新本地資料
            allData.forEach(function(d){ if (d.track_id == id) d.boss_reviewed = 1; });
            lastStats.boss_unread = Math.max(0, lastStats.boss_unread - 1);
            updateStats();
            renderPageRows(allData);
            fetchTableData(currentPage);
        } else showToast('操作失敗：'+(r.message||''), false);
    }, 'json');
}

// ─── 進度說明 Modal ───────────────────────────────────────
function openNotesModal(trackId) {
    $('#nm-track-id').val(trackId);
    $('#nm-new-note').val('');
    $('#nm-notes-list').html('<div class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#notesModal').modal('show');
    loadNotes(trackId);
}
function loadNotes(trackId) {
    $.post(API, {action:'get_notes', track_id:trackId}, function(r) {
        var list = $('#nm-notes-list').empty();
        if (!r.success || !r.data.length) {
            list.html('<p class="text-muted" style="text-align:center;font-size:13px;">尚無進度說明</p>');
            return;
        }
        r.data.forEach(function(n) {
            var updInfo = n.updated_at ? ' <span style="color:#e67e22;">[修改：'+esc(n.updated_by_name||'')+' '+esc((n.updated_at||'').substring(0,10))+']</span>' : '';
            var editBtn = (CAN_UPDATE||CAN_CREATE) ? '<button class="btn btn-xs btn-link" onclick="editNote('+n.note_id+',this)" title="修改"><i class="fa fa-pencil"></i></button>' : '';
            var delBtn  = CAN_DELETE ? '<button class="btn btn-xs btn-link text-danger" onclick="deleteNote('+n.note_id+','+trackId+')" title="刪除"><i class="fa fa-trash"></i></button>' : '';
            var uploadBtn = (CAN_UPDATE||CAN_CREATE) ?
                '<label class="btn btn-xs btn-default" style="cursor:pointer;padding:1px 6px;border-radius:10px;font-size:10px;margin-left:4px;font-weight:400;" title="上傳圖片"><i class="fa fa-image"></i> 附圖<input type="file" accept="image/*" style="display:none;" onchange="uploadNoteImage(this,'+n.note_id+','+trackId+')"></label>' : '';
            // 圖片列表
            var imgsHtml = '';
            if (n.images && n.images.length) {
                imgsHtml = '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">';
                n.images.forEach(function(img) {
                    imgsHtml += '<div style="position:relative;">';
                    imgsHtml += '<a href="'+esc(img.url)+'" target="_blank"><img src="'+esc(img.url)+'" style="height:52px;width:52px;object-fit:cover;border-radius:4px;border:1px solid #ddd;" title="'+esc(img.original_name||img.file_name)+'" onerror="this.style.display=\'none\'"></a>';
                    if (CAN_DELETE||CAN_UPDATE) imgsHtml += '<button onclick="deleteNoteImage('+img.img_id+','+n.note_id+','+trackId+')" style="position:absolute;top:-5px;right:-5px;width:16px;height:16px;border-radius:8px;background:#e74c3c;color:#fff;border:none;font-size:10px;cursor:pointer;line-height:16px;padding:0;text-align:center;">&times;</button>';
                    imgsHtml += '</div>';
                });
                imgsHtml += '</div>';
            }
            list.append('<div class="note-item" id="note-'+n.note_id+'">'+
                '<div style="display:flex;justify-content:space-between;align-items:flex-start;">'+
                '<div class="ni-text" id="note-text-'+n.note_id+'">'+esc(n.note_text)+'</div>'+
                '<div style="white-space:nowrap;">'+editBtn+delBtn+'</div></div>'+
                imgsHtml+
                '<div class="ni-meta" style="margin-top:5px;">'+esc(n.created_by_name||'')+'&nbsp;'+esc((n.created_at||'').substring(0,10))+updInfo+uploadBtn+'</div>'+
            '</div>');
        });
    }, 'json');
}
function addNote() {
    var text = $('#nm-new-note').val().trim();
    var trackId = $('#nm-track-id').val();
    if (!text) { showToast('請輸入內容', false); return; }
    $.post(API, {action:'save_note', track_id:trackId, note_id:0, note_text:text}, function(r) {
        if (r.success) {
            $('#nm-new-note').val('');
            loadNotes(trackId);
            fetchTableData(currentPage);
        } else showToast('失敗：'+(r.message||''), false);
    }, 'json');
}
function editNote(noteId, btnEl) {
    var textDiv = $('#note-text-'+noteId);
    var oldText = textDiv.text();
    var trackId = $('#nm-track-id').val();
    textDiv.replaceWith('<textarea class="form-control" id="note-edit-'+noteId+'" rows="2" style="font-size:13px;">'+esc(oldText)+'</textarea>'+
        '<div style="margin-top:4px;"><button class="btn btn-xs btn-primary" onclick="saveNoteEdit('+noteId+','+trackId+')">儲存</button> '+
        '<button class="btn btn-xs btn-default" onclick="loadNotes('+trackId+')">取消</button></div>');
}
function saveNoteEdit(noteId, trackId) {
    var text = $('#note-edit-'+noteId).val().trim();
    if (!text) { showToast('內容不可為空', false); return; }
    $.post(API, {action:'save_note', track_id:trackId, note_id:noteId, note_text:text}, function(r) {
        if (r.success) loadNotes(trackId);
        else showToast('失敗：'+(r.message||''), false);
    }, 'json');
}
function deleteNote(noteId, trackId) {
    var v = prompt('確認刪除此備註？\n請輸入大寫 Y 確認：');
    if (v !== 'Y') return;
    $.post(API, {action:'delete_note', note_id:noteId, track_id:trackId}, function(r) {
        if (r.success) { loadNotes(trackId); fetchTableData(currentPage); }
        else showToast('失敗', false);
    }, 'json');
}

// ─── 基本設定 Modal ───────────────────────────────────────
var settingsCurTab = '';
function openSettingsModal() {
    settingsCurTab = '';
    $('#settingsModal').modal('show');
    setTimeout(function(){ loadSettingsTab('unit', $('.st-nav-btn').first()[0]); }, 200);
}
function loadSettingsTab(tab, btn) {
    if (settingsCurTab === tab) return;
    settingsCurTab = tab;
    $('.st-nav-btn').removeClass('active');
    if (btn) $(btn).addClass('active');
    var c = $('#settings-content').html('<div class="text-center text-muted" style="margin-top:60px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');

    if (tab === 'unit') {
        $.post(API, {action:'get_sales_unit_info'}, function(r) {
            var dname = r.dept_name || '（未設定）';
            var did   = r.dept_id   || '—';
            $.post(API, {action:'get_settings'}, function(s) {
                var nasDir = (s.data && s.data.sales_nas_dir) || 'Z:/BOM/ERP/業務/';
                var urlDir = (s.data && s.data.sales_url_dir) || '/nas/ERP/業務/';
                var isA = (PERM === 'A');
                var nasInput = isA ?
                    '<div style="display:flex;gap:6px;margin-top:4px;"><input type="text" class="form-control input-sm" id="cfg-nas-dir" value="'+esc(nasDir)+'" style="flex:1;font-family:monospace;"><button class="btn btn-xs btn-primary" onclick="saveSettingKey(\'sales_nas_dir\',\'cfg-nas-dir\')"><i class="fa fa-save"></i> 儲存</button></div>' :
                    '<div style="font-family:monospace;font-size:12px;color:#333;background:#f5f5f5;padding:4px 8px;border-radius:4px;margin-top:4px;">'+esc(nasDir)+'</div>';
                var urlInput = isA ?
                    '<div style="display:flex;gap:6px;margin-top:4px;"><input type="text" class="form-control input-sm" id="cfg-url-dir" value="'+esc(urlDir)+'" style="flex:1;font-family:monospace;"><button class="btn btn-xs btn-primary" onclick="saveSettingKey(\'sales_url_dir\',\'cfg-url-dir\')"><i class="fa fa-save"></i> 儲存</button></div>' :
                    '<div style="font-family:monospace;font-size:12px;color:#333;background:#f5f5f5;padding:4px 8px;border-radius:4px;margin-top:4px;">'+esc(urlDir)+'</div>';
                var bossUserId   = (s.data && s.data.boss_review_user_id)   || '';
                var bossUserName = (s.data && s.data.boss_review_user_name) || '';
                var bossDeptId   = (s.data && s.data.boss_review_dept_id)   || '';
                var canBossEdit  = (PERM === 'A' || PERM === 'CDRU');
                var bossEditHtml = canBossEdit ?
                    '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;">'+
                        '<div><div style="font-size:12px;color:#555;margin-bottom:2px;">部門</div>'+
                        '<select id="boss-dept-sel" class="form-control input-sm" style="min-width:140px;" onchange="loadBossUserOptions()">'+
                        '<option value="">請選擇部門</option></select></div>'+
                        '<div><div style="font-size:12px;color:#555;margin-bottom:2px;">人員</div>'+
                        '<select id="boss-user-sel" class="form-control input-sm" style="min-width:140px;">'+
                        '<option value="">先選部門</option></select></div>'+
                        '<button class="btn btn-sm btn-primary" style="margin-bottom:0;" onclick="saveBossReviewUser()"><i class="fa fa-save"></i> 儲存</button>'+
                        '<button class="btn btn-sm btn-default" style="margin-bottom:0;" onclick="clearBossReviewUser()"><i class="fa fa-times"></i> 清除</button>'+
                    '</div>' :
                    '<div style="font-size:11px;color:#bbb;margin-top:8px;"><i class="fa fa-lock"></i> 需 A 或 CDRU 權限才可修改</div>';
                c.html(
                    '<div style="font-size:13px;font-weight:700;color:var(--prim);margin-bottom:14px;"><i class="fa fa-cog"></i> 基本設定</div>'+
                    '<div style="font-size:11px;font-weight:700;color:#7fa8c9;letter-spacing:.6px;margin-bottom:6px;"><i class="fa fa-building"></i> 業務單位</div>'+
                    '<div style="border:1px solid #d9e6f2;border-radius:6px;padding:12px 16px;background:#f6fafd;margin-bottom:12px;">'+
                        '<div style="font-size:18px;color:#2A3F54;font-weight:700;">'+esc(dname)+'</div>'+
                        '<div style="font-size:11px;color:#b0bec5;margin-top:3px;">部門 ID：'+esc(did)+'</div>'+
                    '</div>'+
                    '<div style="font-size:11px;font-weight:700;color:#7fa8c9;letter-spacing:.6px;margin-bottom:6px;"><i class="fa fa-eye"></i> 已閱使用者</div>'+
                    '<div style="border:1px solid #e4e8ed;border-radius:6px;padding:14px 16px;background:#fff;margin-bottom:16px;">'+
                        '<div style="font-size:12px;color:#555;">目前設定：<strong id="boss-user-display" style="color:var(--prim);">'+(bossUserName||'（未設定）')+'</strong></div>'+
                        bossEditHtml+
                    '</div>'+
                    '<div style="border-left:3px solid #c8a84b;background:#fdf8ee;padding:8px 12px;border-radius:0 4px 4px 0;font-size:11px;color:#7a6020;margin-bottom:16px;">'+
                        '<i class="fa fa-info-circle"></i> 若需修改業務部門設定，請至 BOM總覽頁面修改。此設定影響「負責業務」下拉選單的人員清單範圍。'+
                    '</div>'+
                    '<div style="font-size:11px;font-weight:700;color:#7fa8c9;letter-spacing:.6px;margin-bottom:6px;"><i class="fa fa-folder-open-o"></i> 圖片儲存路徑</div>'+
                    '<div style="border:1px solid #e4e8ed;border-radius:6px;padding:14px 16px;background:#fff;">'+
                        '<div style="margin-bottom:12px;">'+
                            '<label style="font-size:12px;color:#555;margin-bottom:0;">NAS 實體路徑（後端寫檔）</label>'+
                            nasInput+
                            '<div style="font-size:10px;color:#bbb;margin-top:2px;">例：Z:/BOM/ERP/業務/</div>'+
                        '</div>'+
                        '<div>'+
                            '<label style="font-size:12px;color:#555;margin-bottom:0;">URL 前綴（前端顯示）</label>'+
                            urlInput+
                            '<div style="font-size:10px;color:#bbb;margin-top:2px;">例：/nas/ERP/業務/</div>'+
                        '</div>'+
                        (!isA ? '<div style="margin-top:10px;font-size:11px;color:#bbb;"><i class="fa fa-lock"></i> 僅權限 A 可修改路徑設定</div>' : '')+
                    '</div>'
                );
                if (canBossEdit) initBossUserDropdowns(bossDeptId, bossUserId);
            }, 'json');
        }, 'json');
    } else if (tab === 'labels') {
        loadLabelsSettingsTab(c);
    }
}

function loadLabelsSettingsTab(c) {
    $.post(API, {action:'manage_labels', op:'list'}, function(r) {
        var canEdit = <?= json_encode($can_settings_edit) ?>;
        var html = '<div style="font-size:14px;font-weight:700;color:var(--prim);margin-bottom:12px;"><i class="fa fa-tags"></i> 標籤分類管理</div>';
        if (canEdit) {
            html += '<div style="display:flex;gap:8px;margin-bottom:14px;">'+
                '<input type="text" class="form-control input-sm" id="new-label-input" placeholder="輸入新標籤名稱" style="max-width:220px;">'+
                '<button class="btn btn-sm btn-primary" onclick="addLabel()"><i class="fa fa-plus"></i> 新增</button></div>';
        }
        var colSpan = canEdit ? 5 : 4;
        html += '<table class="table table-condensed table-bordered" style="font-size:12px;"><thead><tr><th>標籤名稱</th><th style="width:40px;">排序</th><th style="width:120px;">顏色</th><th style="width:50px;">狀態</th>';
        if (canEdit) html += '<th style="width:90px;">操作</th>';
        html += '</tr></thead><tbody id="labels-tbody">';
        if (!r.success || !r.data.length) {
            html += '<tr><td colspan="'+colSpan+'" class="text-center text-muted">尚無標籤</td></tr>';
        } else {
            r.data.forEach(function(l) {
                var curColor = l.color || '#1ABB9C';
                var stateBadge = l.is_active==1 ? '<span class="label label-success" style="font-size:10px;">啟用</span>' : '<span class="label label-default" style="font-size:10px;">停用</span>';
                var editBtn='', delBtn='';
                if (canEdit) {
                    editBtn = '<button class="btn btn-xs btn-info" onclick="editLabelName('+l.label_id+',\''+esc(l.label_name)+'\')"><i class="fa fa-pencil"></i></button>';
                    if (l.is_active==1) delBtn = ' <button class="btn btn-xs btn-warning" onclick="toggleLabel('+l.label_id+',0)" title="停用"><i class="fa fa-ban"></i></button>';
                    else delBtn = ' <button class="btn btn-xs btn-success" onclick="toggleLabel('+l.label_id+',1)" title="啟用"><i class="fa fa-check"></i></button>';
                    delBtn += ' <button class="btn btn-xs btn-danger" onclick="hardDeleteLabel('+l.label_id+')" title="刪除"><i class="fa fa-trash"></i></button>';
                }
                // 顏色格（色塊＋10個預設色點可點擊）
                var colorCell = '<td>';
                colorCell += '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:'+curColor+';border:1px solid rgba(0,0,0,.25);vertical-align:middle;margin-right:4px;"></span>';
                if (canEdit) {
                    PRESET_COLORS.forEach(function(pc) {
                        var isSel = curColor.toUpperCase()===pc.toUpperCase();
                        colorCell += '<span onclick="setLabelColor('+l.label_id+',\''+pc+'\')" title="'+pc+'" style="display:inline-block;width:13px;height:13px;border-radius:50%;background:'+pc+';cursor:pointer;vertical-align:middle;margin:0 1px;border:'+(isSel?'2px solid #333':'2px solid transparent')+';box-sizing:border-box;"></span>';
                    });
                }
                colorCell += '</td>';

                html += '<tr data-lid="'+l.label_id+'"><td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'+curColor+';margin-right:4px;vertical-align:middle;"></span>'+esc(l.label_name)+'</td><td>'+l.sort_order+'</td>'+colorCell+'<td>'+stateBadge+'</td>';
                if (canEdit) html += '<td style="white-space:nowrap;">'+editBtn+delBtn+'</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table>';
        if (!canEdit) html += '<p style="color:#aaa;font-size:11px;"><i class="fa fa-lock"></i> 需 A 或 CDRU 權限才可修改標籤設定</p>';
        c.html(html);
        if (canEdit) initLabelDrag();
    }, 'json');
}

function addLabel() {
    var name = $('#new-label-input').val().trim();
    if (!name) { showToast('請輸入標籤名稱', false); return; }
    $.post(API, {action:'manage_labels', op:'save', label_name:name}, function(r) {
        if (r.success) {
            $('#new-label-input').val('');
            loadLabels();
            loadLabelsSettingsTab($('#settings-content'));
        } else showToast('失敗：'+(r.message||''), false);
    }, 'json');
}
function editLabelName(lid, oldName) {
    var newName = prompt('修改標籤名稱：', oldName);
    if (!newName || newName === oldName) return;
    $.post(API, {action:'manage_labels', op:'save', label_id:lid, label_name:newName}, function(r) {
        if (r.success) { loadLabels(); loadLabelsSettingsTab($('#settings-content')); }
        else showToast('失敗：'+(r.message||''), false);
    }, 'json');
}
function toggleLabel(lid, active) {
    $.post(API, {action:'manage_labels', op:active?'enable':'disable', label_id:lid}, function(r) {
        loadLabels();
        loadLabelsSettingsTab($('#settings-content'));
    }, 'json');
}
function hardDeleteLabel(lid) {
    var v = prompt('確認刪除此標籤？（已使用的標籤對應也會移除）\n請輸入大寫 Y 確認：');
    if (v !== 'Y') return;
    $.post(API, {action:'manage_labels', op:'delete', label_id:lid}, function(r) {
        loadLabels();
        loadLabelsSettingsTab($('#settings-content'));
    }, 'json');
}

// ─── 說明詳情 Popup ──────────────────────────────────────
function showDescDetail(trackId, e) {
    if (e) e.preventDefault();
    var row = allData.find(function(d){ return d.track_id == trackId; });
    if (!row) return;
    var html = '';
    if (row.description) {
        html += '<div style="white-space:pre-wrap;word-break:break-word;font-size:13px;color:#333;line-height:1.7;margin-bottom:12px;">'+esc(row.description)+'</div>';
    }
    if (row.track_images && row.track_images.length) {
        html += '<div style="font-size:11px;color:#aaa;margin-bottom:8px;"><i class="fa fa-image"></i> 附圖（'+row.track_images.length+' 張）</div>';
        html += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
        row.track_images.forEach(function(img) {
            html += '<a href="'+esc(img.url)+'" target="_blank"><img src="'+esc(img.url)+'" style="max-height:200px;max-width:280px;width:auto;height:auto;border-radius:4px;border:1px solid #ddd;" onerror="this.closest(\'a\').style.display=\'none\'"></a>';
        });
        html += '</div>';
    }
    if (!html) html = '<p class="text-muted text-center">無說明內容</p>';
    $('#desc-detail-body').html(html);
    var tc = row.track_code || ('#'+row.track_id);
    $('#desc-detail-title').html('<i class="fa fa-align-left"></i> 說明詳情 — '+esc(tc));
    $('#descDetailModal').modal('show');
}

// ─── 說明附圖 ────────────────────────────────────────────
function loadTrackImages(trackId) {
    $.post(API, {action:'get_track_images', target_type:'track', target_id:trackId}, function(r) {
        var wrap = $('#tm-imgs-list').empty();
        if (!r.success || !r.data.length) return;
        r.data.forEach(function(img) {
            var el = $('<div>').css({position:'relative', display:'inline-block'});
            el.append('<a href="'+esc(img.url)+'" target="_blank"><img src="'+esc(img.url)+'" title="'+esc(img.original_name||img.file_name)+'" style="max-height:110px;max-width:200px;width:auto;height:auto;border-radius:5px;border:1px solid #ddd;display:block;" onerror="this.closest(\'div\').style.display=\'none\'"></a>');
            if (CAN_DELETE || CAN_UPDATE) {
                el.append($('<button>').text('×').css({position:'absolute',top:'-5px',right:'-5px',width:'17px',height:'17px',borderRadius:'9px',background:'#e74c3c',color:'#fff',border:'none',fontSize:'11px',cursor:'pointer',lineHeight:'17px',padding:'0',textAlign:'center'})
                    .on('click', function(){ deleteTrackImage(img.img_id, trackId); }));
            }
            wrap.append(el);
        });
    }, 'json');
}
function uploadTrackImage(input) {
    var file = input.files[0];
    if (!file) return;
    var trackId = $('#tm-track-id').val();
    var fd = new FormData();
    fd.append('action', 'upload_track_image');
    fd.append('target_type', 'track');
    fd.append('target_id', trackId);
    fd.append('image', file);
    $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json',
        success:function(r){
            if (r.success) loadTrackImages(trackId);
            else showToast('上傳失敗：'+(r.message||''), false);
        }
    });
    $(input).val('');
}
function deleteTrackImage(imgId, trackId) {
    if (!confirm('確認刪除此圖片？')) return;
    $.post(API, {action:'delete_track_image', img_id:imgId}, function(r) {
        if (r.success) loadTrackImages(trackId);
        else showToast('刪除圖片失敗', false);
    }, 'json');
}

// ─── 進度說明圖片 ─────────────────────────────────────────
function uploadNoteImage(input, noteId, trackId) {
    var file = input.files[0];
    if (!file) return;
    var fd = new FormData();
    fd.append('action', 'upload_track_image');
    fd.append('target_type', 'note');
    fd.append('target_id', noteId);
    fd.append('image', file);
    $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json',
        success:function(r){
            if (r.success) loadNotes(trackId);
            else showToast('上傳失敗：'+(r.message||''), false);
        }
    });
    $(input).val('');
}
function deleteNoteImage(imgId, noteId, trackId) {
    if (!confirm('確認刪除此圖片？')) return;
    $.post(API, {action:'delete_track_image', img_id:imgId}, function(r) {
        if (r.success) loadNotes(trackId);
        else showToast('刪除圖片失敗', false);
    }, 'json');
}

// ─── 標籤顏色 ────────────────────────────────────────────
function setLabelColor(lid, color) {
    $.post(API, {action:'manage_labels', op:'save_color', label_id:lid, color:color}, function(r) {
        if (r.success) { loadLabels(); loadLabelsSettingsTab($('#settings-content')); }
        else showToast('設定顏色失敗', false);
    }, 'json');
}

// ─── 基本設定存檔 ─────────────────────────────────────────
function saveSettingKey(key, inputId) {
    var val = $('#'+inputId).val().trim();
    if (!val) { showToast('路徑不可為空', false); return; }
    $.post(API, {action:'save_settings', setting_key:key, setting_value:val}, function(r) {
        if (r.success) showToast('已儲存', true);
        else showToast('儲存失敗：'+(r.message||''), false);
    }, 'json');
}

// ─── 通知 ─────────────────────────────────────────────────
var _notifTimer = null;
function startNotifPolling(){
    if(_notifTimer) return;
    _scheduleNotifPoll(500); // 頁面載入後 0.5 秒先做第一次
}
function _scheduleNotifPoll(delay){
    _notifTimer = setTimeout(function(){
        _notifTimer = null;
        if(document.visibilityState==='hidden'){ _scheduleNotifPoll(1000); return; }
        $.post(API,{action:'get_notifications'},function(r){
            if(r.success && r.notifications && r.notifications.length){
                var hasNew=false;
                r.notifications.forEach(function(n){
                    if(!$('#st-notif-'+n.notif_id).length){ showTrackNotification(n); hasNew=true; }
                });
                if(hasNew) loadList();
            }
        },'json').always(function(){ _scheduleNotifPoll(1000); }); // 無論成功失敗，1 秒後再次輪詢
    }, delay);
}
function pollNotifications(){ /* 保留供外部呼叫 */ _scheduleNotifPoll(0); }
function showTrackNotification(n){
    var panel=$('#st-notif-panel');
    if($('#st-notif-'+n.notif_id).length) return;
    var iconMap={create:'fa-plus-circle',update:'fa-pencil',complete:'fa-check-circle',delete:'fa-trash'};
    var colorMap={create:'#27AE60',update:'#2980B9',complete:'#1ABB9C',delete:'#E74C3C'};
    var icon=iconMap[n.action_type]||'fa-bell';
    var color=colorMap[n.action_type]||'#555';
    var card=$('<div id="st-notif-'+n.notif_id+'" class="st-notif-card" style="border-left:4px solid '+color+';">'
        +'<div style="font-weight:700;color:'+color+';margin-bottom:4px;"><i class="fa '+icon+'"></i> 業務追蹤通知</div>'
        +'<div style="font-size:12px;color:#333;">'+esc(n.message)+'</div>'
        +'<div style="font-size:10px;color:#aaa;margin-top:4px;">'+esc((n.created_at||'').substring(0,16))+'</div>'
        +'<button onclick="dismissNotif('+n.notif_id+',event)" style="position:absolute;top:6px;right:8px;background:none;border:none;color:#aaa;font-size:15px;cursor:pointer;">&times;</button>'
        +'</div>');
    if(n.track_id) card.on('click',function(e){ if($(e.target).is('button')) return; dismissNotif(n.notif_id,e); jumpToTrack(n.track_id,n.notif_id); });
    panel.append(card);
    setTimeout(function(){ card.fadeOut(400,function(){ card.remove(); }); },300000);
}
function dismissNotif(notifId,e){
    if(e) e.stopPropagation();
    $('#st-notif-'+notifId).fadeOut(200,function(){ $(this).remove(); });
    $.post(API,{action:'mark_notification_read',notif_id:notifId},function(){},'json');
}
function highlightRow(row){
    $('html,body').animate({scrollTop:row.offset().top-120},400);
    row.addClass('track-hl-row');
    setTimeout(function(){ row.removeClass('track-hl-row'); },3000);
}
function jumpToTrack(trackId,notifId){
    if(notifId) dismissNotif(notifId,null);
    // 清除所有篩選，找出此追蹤項目所在頁碼後跳轉
    activeFilter='all'; activeTagIds=[]; globalSearch='';
    $('#fltSource,#fltAssignee,#fltCustomer,#fltSearch').val('');
    renderTagChips(); updateStats();
    $.post(API,{action:'find_track_page',track_id:trackId},function(r){
        var pg=(r&&r.success)?r.page:1;
        fetchTableData(pg, function(){
            var row=$('tr[data-track-id="'+trackId+'"]');
            if(row.length) highlightRow(row);
        });
    },'json');
}

// ─── 標籤拖曳排序 ─────────────────────────────────────────
function initLabelDrag(){
    var srcLid=null;
    var tbody=$('#labels-tbody');
    tbody.find('tr[data-lid]').attr('draggable','true')
        .on('dragstart',function(){ srcLid=$(this).data('lid'); $(this).css('opacity','0.4'); })
        .on('dragend',function(){ $(this).css('opacity','1'); tbody.find('tr').css('border-top',''); })
        .on('dragover',function(e){ e.preventDefault(); tbody.find('tr').css('border-top',''); $(this).css('border-top','2px solid #1ABB9C'); })
        .on('dragleave',function(){ $(this).css('border-top',''); })
        .on('drop',function(e){
            e.preventDefault();
            var tgtLid=$(this).data('lid');
            if(!srcLid||srcLid===tgtLid){ tbody.find('tr').css('border-top',''); return; }
            tbody.find('tr[data-lid="'+srcLid+'"]').insertBefore($(this));
            tbody.find('tr').css({'opacity':'1','border-top':''});
            var newOrder=[];
            tbody.find('tr[data-lid]').each(function(){ newOrder.push($(this).data('lid')); });
            $.post(API,{action:'manage_labels',op:'sort',ids:JSON.stringify(newOrder)},function(r){
                if(r.success){ loadLabels(); showToast('排序已儲存',true); }
            },'json');
            srcLid=null;
        });
}

// ─── 歷史紀錄 Modal ───────────────────────────────────────
var _histTrackId = 0;
var _histPeriod  = 'today';

function openHistoryModal(trackId) {
    _histTrackId = trackId || 0;
    _histPeriod  = 'today';
    // 重置篩選列
    $('#hist-period-btns button').removeClass('btn-primary').addClass('btn-default');
    $('#hist-period-btns button:first').removeClass('btn-default').addClass('btn-primary');
    $('#hist-custom-range').hide();
    $('#hf-insert,#hf-update,#hf-delete').prop('checked', true);
    $('#hist-count').text('');
    $('#historyModal').modal('show');
    loadHistory();
}

function setHistPeriod(period, btn) {
    _histPeriod = period;
    $('#hist-period-btns button').removeClass('btn-primary').addClass('btn-default');
    $(btn).removeClass('btn-default').addClass('btn-primary');
    if (period === 'custom') {
        var today = new Date().toISOString().split('T')[0];
        $('#hist-date-from,#hist-date-to').val(today);
        $('#hist-custom-range').css('display','inline-flex');
    } else {
        $('#hist-custom-range').hide();
        loadHistory();
    }
}

function getHistDates() {
    var today = new Date();
    var fmt = function(d){ return d.toISOString().split('T')[0]; };
    if (_histPeriod === 'today') {
        var t = fmt(today); return {from:t, to:t};
    } else if (_histPeriod === 'week') {
        var dow = today.getDay();
        var mon = new Date(today);
        mon.setDate(today.getDate() - (dow === 0 ? 6 : dow - 1));
        return {from:fmt(mon), to:fmt(today)};
    } else if (_histPeriod === 'month') {
        return {from:fmt(new Date(today.getFullYear(), today.getMonth(), 1)), to:fmt(today)};
    } else {
        return {from:$('#hist-date-from').val(), to:$('#hist-date-to').val()};
    }
}

function loadHistory() {
    var dates = getHistDates();
    var types = [];
    if ($('#hf-insert').is(':checked')) types.push('insert');
    if ($('#hf-update').is(':checked')) types.push('update');
    if ($('#hf-delete').is(':checked')) types.push('delete');
    if (!types.length) { $('#history-content').html('<p class="text-muted text-center" style="padding:20px;">請至少選擇一種動作類型</p>'); return; }

    $('#history-content').html('<div class="text-center text-muted" style="padding:30px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
    $.post(API, {
        action: 'get_history',
        track_id: _histTrackId,
        date_from: dates.from,
        date_to:   dates.to,
        action_types: types.join(',')
    }, function(r) {
        var c = $('#history-content').empty();
        if (!r.success || !r.data.length) {
            c.html('<p class="text-muted text-center" style="padding:30px;font-size:13px;">此期間無符合的歷史紀錄</p>');
            $('#hist-count').text('');
            return;
        }
        $('#hist-count').text('共 '+r.data.length+' 筆');
        var actMap   = {insert:'新增', update:'修改', delete:'刪除'};
        var actColor = {insert:'#27ae60', update:'#2980b9', delete:'#e74c3c'};
        var html = '<div style="overflow-x:auto;"><table class="table table-condensed table-bordered" style="font-size:12px;">';
        html += '<thead><tr style="background:#f5f7fa;">'
            + '<th style="width:130px;white-space:nowrap;">時間 / 操作人</th>'
            + '<th style="width:46px;">操作</th>'
            + '<th style="width:130px;">追蹤編號</th>'
            + '<th style="width:120px;">料號/圖號</th>'
            + '<th>變更內容</th>'
            + '</tr></thead><tbody>';
        r.data.forEach(function(h) {
            var actLabel = actMap[h.action_type] || h.action_type;
            var color    = actColor[h.action_type] || '#555';
            var time     = (h.created_at||'').substring(0, 16);
            var changes  = '—';
            if (h.changes) {
                try {
                    var chArr = JSON.parse(h.changes);
                    if (Array.isArray(chArr) && chArr.length) {
                        changes = chArr.map(function(ch){
                            return '<span style="color:#888;">'+esc(ch.field||'')+'</span> '
                                 + '<del style="color:#e74c3c;">'+esc(String(ch.old||''))+'</del>'
                                 + ' → <span style="color:#27ae60;">'+esc(String(ch.new||''))+'</span>';
                        }).join('<br>');
                    }
                } catch(e) { changes = esc(h.changes); }
            }
            html += '<tr>'
                + '<td style="white-space:nowrap;"><div style="color:#555;font-size:11px;">'+esc(time)+'</div>'
                + '<div style="color:#aaa;font-size:10px;text-align:right;">'+esc(h.operator||'')+'</div></td>'
                + '<td><span style="color:'+color+';font-weight:700;font-size:11px;">'+esc(actLabel)+'</span></td>'
                + '<td style="font-size:11px;color:#2A3F54;font-family:monospace;">'+esc(h.target_name||('#'+h.target_id))+'</td>'
                + '<td style="font-size:11px;color:#888;white-space:nowrap;">'+esc(h.part_display||'—')+'</td>'
                + '<td style="font-size:11px;color:#555;line-height:1.8;">'+changes+'</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        c.html(html);
    }, 'json');
}

// ─── BOSS 已閱使用者設定 ──────────────────────────────────
function initBossUserDropdowns(deptId, userId) {
    var deptSel = $('#boss-dept-sel').empty().append('<option value="">請選擇部門</option>');
    allDepts.forEach(function(d) {
        deptSel.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
    });
    if (!deptId) return;
    deptSel.val(deptId);
    var uSel = $('#boss-user-sel').empty().append('<option value="">請選擇人員</option>');
    var deptIdInt = parseInt(deptId, 10);
    var fillUser = function(users) {
        populateUsers(uSel, users);
        if (userId) uSel.val(userId);
    };
    if (deptUserCache[deptIdInt]) {
        fillUser(deptUserCache[deptIdInt]);
    } else {
        $.post(API, {action:'get_dept_users', dept_id:deptIdInt}, function(r) {
            if (r.success) { deptUserCache[deptIdInt]=r.data; fillUser(r.data); }
        }, 'json');
    }
}

function loadBossUserOptions() {
    var deptId = $('#boss-dept-sel').val();
    var uSel = $('#boss-user-sel').empty().append('<option value="">請選擇人員</option>');
    if (!deptId) return;
    if (deptUserCache[deptId]) { populateUsers(uSel, deptUserCache[deptId]); return; }
    $.post(API, {action:'get_dept_users', dept_id:deptId}, function(r) {
        if (r.success) { deptUserCache[deptId]=r.data; populateUsers(uSel, r.data); }
    }, 'json');
}

function saveBossReviewUser() {
    var userId = $('#boss-user-sel').val();
    var deptId = $('#boss-dept-sel').val();
    if (!userId) { showToast('請選擇人員', false); return; }
    $.post(API, {action:'save_boss_review_user', boss_user_id:userId, boss_dept_id:deptId}, function(r) {
        if (r.success) {
            var raw = $('#boss-user-sel option:selected').text();
            var uName = raw.replace(/ \(.*\)$/, '');
            $('#boss-user-display').text(uName);
            showToast('BOSS 已閱使用者已儲存', true);
        } else showToast('儲存失敗：'+(r.message||''), false);
    }, 'json');
}

function clearBossReviewUser() {
    if (!confirm('確認清除 BOSS 已閱使用者設定？')) return;
    $.post(API, {action:'save_boss_review_user', boss_user_id:0, boss_dept_id:0}, function(r) {
        if (r.success) {
            $('#boss-user-display').text('（未設定）');
            $('#boss-dept-sel').val('');
            $('#boss-user-sel').empty().append('<option value="">先選部門</option>');
            showToast('已清除', true);
        } else showToast('失敗', false);
    }, 'json');
}
</script>
</body>
</html>
