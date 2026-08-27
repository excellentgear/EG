<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage'] = "../../views/reply/reply_other.php?BOM=" . $_GET['BOM'] . "&d_id=" . $_GET['d_id'] . "&ProcessNo=" . $_GET['ProcessNo'] . "&sqty=" . $_GET['sqty'] . "&D=" . $_GET['D'] . "&C=" . $_GET['C'] . "&m=" . $_GET['m'];
    header("Location:../../index.php?BOM=" . $_GET['BOM'] . "&d_id=" . $_GET['d_id'] . "&ProcessNo=" . $_GET['ProcessNo'] . "&sqty=" . $_GET['sqty'] . "&D=" . $_GET['D'] . "&C=" . $_GET['C'] . "&m=" . $_GET['m']); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$id = $_GET['id'];
@$new = $_GET['new'];

@$userName      = $_SESSION['user_cname'];
@$id            = $_SESSION['id'];
@$user_status   = $_SESSION['status'];

@$BOM           = $_GET['BOM'];
@$ProcessNo     = $_GET['ProcessNo'];
@$sqty          = $_GET['sqty'];
@$Client_Name   = $_GET['C'];
@$MakerId       = $_GET['MakerId'];

@$bom           = $_GET['bom'];
@$outsource_date = $_GET['outsource_date'];
@$ProcessName   = $_GET['pna'];
@$bom_ing_id    = $_GET['bi'];
@$d_id          = $_GET['d'];
@$replydate     = $_GET['rd']; // 更新日期

// 更新報工紀錄
// @$oready_sqty = $_SESSION['oready_sqty'];
// @$ok_sqty     = $_SESSION['ok_sqty'];
// @$ng_sqty     = $_SESSION['ng_sqty'];
// @$ng_id       = $_SESSION['ng_id'];
// @$ng_sqty2    = $_SESSION['ng_sqty2'];
// @$ng_id2      = $_SESSION['ng_id2'];
// @$ng_sqty3    = $_SESSION['ng_sqty3'];
// @$ng_id3      = $_SESSION['ng_id3'];
// @$completed   = $_SESSION['completed'];
// @$Created_By  = $_SESSION['Created_By'];
// @$Created_At  = $_SESSION['Created_At'];


@$conn = new DBConnection();

// NG原因（供 Modal 使用）
@$ng_txt_list  = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");
@$ng_txt_list2 = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");
@$ng_txt_list3 = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");

// 2=齒研機
@$machine_id_list = $conn->getAll("SELECT `machine_id`,`machine` FROM machine_list WHERE `machine_type_id`=2 ORDER BY `machine_id`");

@$last_sqty = $_SESSION['last_sqty'];

// 篩選按鈕定義（純靜態，不查 DB；present_pti_ids 由 API 第一頁回傳後動態渲染）
$all_process_types_map = [
    '138' => '客供料', '1' => '車床', '2' => '銑床', '3' => '拉串、拉(插)栓槽',
    '4' => '滾(插、切)齒', '66' => '滾(研)栓槽', '59' => '倒圓(尖)角', '7' => '熱處理',
    '8' => '線割', '10' => '平研', '11' => '外研', '911' => '孔外研', '9' => '孔平研',
    '33' => '研磨', '164' => '回客戶', '12' => '齒研', '16' => '雷刻與包裝',
    '189' => '其他製程', '202' => '全製'
];
$button_order = ['138','1','2','3','4','66','59','7','8','10','11','911','9','33','164','12','16','189','202'];

@$OreadyReply_list = $conn->getAll("SELECT `BOM`,`Client_Name`,`sqty`,`oready_sqty`,`ProcessName`,`ProcessNo`,`MakerId`,
date(`Created_At`) as Created_At_s,`ok_sqty`,`ng_sqty_total`,`ps` FROM vw_oreadyreply_list ORDER BY Created_At DESC");


@$reply_id = $_GET['ri'];


@$ri = $conn->getAll("SELECT vw_oreadyreply_list.reply_id,vw_oreadyreply_list.BOM,vw_oreadyreply_list.oready_sqty,
                        date(vw_oreadyreply_list.Created_At) as Created_date,vw_oreadyreply_list.Created_At as Created_date_ORDER,vw_oreadyreply_list.Created_By,
                        vw_oreadyreply_list.ok_sqty,vw_oreadyreply_list.ng_sqty_total,user.user_cname,vw_oreadyreply_list.ps,
                        vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width,vw_oreadyreply_list.mc_id,vw_oreadyreply_list.mc_time,
                        vw_oreadyreply_list.processing_time,machine_list.machine,vw_oreadyreply_list.mc_user,vw_oreadyreply_list.sqty,                     vw_oreadyreply_list.oready_sqty,vw_oreadyreply_list.ProcessName,vw_oreadyreply_list.ProcessNo,vw_oreadyreply_list.MakerId,
                        reply.ng_sqty,reply.ng_id,reply.ng_sqty2,reply.ng_id2,reply.ng_sqty3,reply.ng_id3
                        FROM vw_oreadyreply_list
                        LEFT JOIN user ON user.id=vw_oreadyreply_list.Created_By
                        LEFT JOIN machine_list ON vw_oreadyreply_list.machine_id=machine_list.machine_id
                        LEFT JOIN reply ON reply.reply_id=vw_oreadyreply_list.reply_id
                        WHERE vw_oreadyreply_list.reply_id='$reply_id'
                        ORDER BY Created_date_ORDER DESC");

if ($reply_id != "") {
    foreach ($ri as $ri) {
        @$oready_sqty = $ri['oready_sqty'];
        @$ok_sqty     = $ri['ok_sqty'];
        @$NG          = $ri['ng_sqty'];
        @$ng_txt_id   = $ri['ng_id'];
        @$NG2         = $ri['ng_sqty2'];
        @$ng_txt_id2  = $ri['ng_id2'];
        @$NG3         = $ri['ng_sqty3'];
        @$ng_txt_id3  = $ri['ng_id3'];
        @$ps          = $ri['ps'];
        @$completed   = $ri['completed'];
        @$Created_By  = $ri['Created_By'];
        @$Created_At  = $ri['Created_At'];
    }
};


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
    /* ═══════════════════════════════════════════════════════
       QC 待驗清單 — 重設計 UI
    ═══════════════════════════════════════════════════════ */
    :root {
        --qc-bg:        #F5F7FA;
        --qc-panel-bg:  #FFFFFF;
        --qc-border:    #E4E9F0;
        --qc-primary:   #2E6DA4;
        --qc-primary-h: #1f4f7a;
        --qc-text:      #3D4B5C;
        --qc-muted:     #8A9BB0;
        --qc-radius:    8px;
        --qc-shadow:    0 2px 12px rgba(0,0,0,.07);
        --qc-green:     #2ECC71;
        --qc-yellow:    #F5A623;
        --qc-gray:      #B0BEC5;
        --qc-red:       #E74C3C;
    }

    .right_col { background: var(--qc-bg) !important; }

    /* ── Panel ── */
    .qc-panel {
        background: var(--qc-panel-bg);
        border-radius: var(--qc-radius);
        box-shadow: var(--qc-shadow);
        overflow: hidden;
        margin-bottom: 20px;
    }

    /* ── Toolbar ── */
    .qc-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px 10px;
        border-bottom: 1px solid var(--qc-border);
        flex-wrap: wrap;
        gap: 8px;
    }
    .qc-toolbar-left { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .qc-toolbar-right { display:flex; align-items:center; gap:8px; }

    .qc-title {
        font-size: 17px;
        font-weight: 700;
        color: var(--qc-text);
        letter-spacing: .3px;
    }
    .qc-count-badge {
        background: #EBF3FB;
        color: var(--qc-primary);
        font-size: 12px;
        font-weight: 700;
        padding: 2px 10px;
        border-radius: 20px;
        border: 1px solid #C5DCF0;
    }
    .btn-qc-add {
        background: var(--qc-primary);
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 5px 12px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: background .15s;
        white-space: nowrap;
    }
    .btn-qc-add:hover { background: var(--qc-primary-h); }

    /* 搜尋框 */
    .qc-search-wrap {
        position: relative;
        display: flex;
        align-items: center;
    }
    .qc-search-icon {
        position: absolute;
        left: 10px;
        color: var(--qc-muted);
        font-size: 13px;
        pointer-events: none;
    }
    .qc-search-input {
        border: 1px solid var(--qc-border);
        border-radius: 20px;
        padding: 5px 28px 5px 30px;
        font-size: 13px;
        width: 220px;
        color: var(--qc-text);
        outline: none;
        transition: border-color .15s, box-shadow .15s;
        background: #F9FAFB;
    }
    .qc-search-input:focus {
        border-color: var(--qc-primary);
        box-shadow: 0 0 0 3px rgba(46,109,164,.12);
        background: #fff;
    }
    .qc-search-clear {
        position: absolute;
        right: 10px;
        cursor: pointer;
        color: var(--qc-muted);
        font-size: 16px;
        line-height: 1;
        transition: color .1s;
    }
    .qc-search-clear:hover { color: var(--qc-red); }

    /* ── 篩選列 ── */
    .qc-filter-bar {
        display: flex;
        align-items: center;
        padding: 8px 18px;
        background: #FAFBFC;
        border-bottom: 1px solid var(--qc-border);
        flex-wrap: wrap;
        gap: 6px;
        min-height: 42px;
    }
    .qc-filter-group {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
    }
    .qc-filter-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--qc-muted);
        text-transform: uppercase;
        letter-spacing: .5px;
        margin-right: 4px;
        white-space: nowrap;
    }
    .qc-filter-divider {
        width: 1px;
        height: 20px;
        background: var(--qc-border);
        margin: 0 6px;
    }

    /* 狀態篩選按鈕 */
    .qc-status-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border: 1px solid var(--qc-border);
        border-radius: 20px;
        background: #fff;
        font-size: 12px;
        color: var(--qc-text);
        cursor: pointer;
        transition: all .15s;
        white-space: nowrap;
    }
    .qc-status-btn:hover { border-color: var(--qc-primary); color: var(--qc-primary); }
    .qc-status-btn.active {
        background: var(--qc-primary);
        border-color: var(--qc-primary);
        color: #fff;
        font-weight: 600;
    }
    .qc-status-btn.active .dot { opacity:1; }

    /* 製程篩選按鈕 */
    .qc-pti-btn {
        padding: 3px 9px;
        border: 1px solid var(--qc-border);
        border-radius: 4px;
        background: #fff;
        font-size: 12px;
        color: var(--qc-text);
        cursor: pointer;
        transition: all .15s;
        white-space: nowrap;
    }
    .qc-pti-btn:hover { border-color: var(--qc-primary); color: var(--qc-primary); background: #EBF3FB; }
    .qc-pti-btn.active {
        background: var(--qc-primary);
        border-color: var(--qc-primary);
        color: #fff;
        font-weight: 600;
    }
    .qc-pti-sep { color: var(--qc-border); font-size: 16px; line-height: 1; margin: 0 2px; }

    /* 重設按鈕 */
    .btn-qc-reset {
        padding: 3px 10px;
        border: 1px solid #f5b8b0;
        border-radius: 4px;
        background: #fff5f4;
        font-size: 12px;
        color: #c0392b;
        cursor: pointer;
        transition: all .15s;
        white-space: nowrap;
    }
    .btn-qc-reset:hover { background: #ffe0dc; }

    /* 色點 */
    .dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
    }
    .dot-gray   { background: var(--qc-gray); }
    .dot-yellow { background: var(--qc-yellow); }
    .dot-green  { background: var(--qc-green); }

    /* ── 表格 ── */
    .qc-table-wrap { overflow-x: auto; }
    .qc-table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 13px;
        margin: 0 !important;
    }
    .qc-table thead th {
        background: #F3F6FA;
        color: #5A6A7E;
        font-weight: 700;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .4px;
        padding: 9px 8px;
        border-bottom: 2px solid var(--qc-border);
        white-space: nowrap;
        vertical-align: middle;
    }
    .qc-table tbody td {
        padding: 7px 8px;
        border-bottom: 1px solid #F0F4F8;
        vertical-align: middle;
        color: var(--qc-text);
    }
    .qc-table tbody tr:hover { background: #F7FBFF !important; }
    .qc-table tbody tr:last-child td { border-bottom: none; }

    /* 欄位寬度 */
    .col-status    { width: 90px; min-width:80px; }
    .col-client    { width: 60px; min-width:50px; }
    .col-bom       { width: 130px; }
    .col-part      { width: 130px; }
    .col-date      { width: 60px; min-width:50px; white-space:nowrap; }
    .col-process   { width: 130px; }
    .col-maker     { width: 80px; }
    .col-qty       { width: 55px; text-align:right; }
    .col-container { width: 80px; }
    .col-remark    { min-width: 160px; white-space: normal; }
    .col-action    { width: 130px; min-width:120px; white-space:nowrap; }

    #qc-data-table tbody td { white-space: nowrap; }
    #qc-data-table tbody td.col-remark { white-space: normal; }

    /* 操作按鈕組 */
    .qc-action-group { display:flex; gap:3px; flex-wrap:nowrap; align-items:center; }
    .qc-action-group .btn { padding: 2px 7px; font-size: 12px; border-radius: 4px; }

    /* ── 分頁列 ── */
    .qc-pagination-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 4px;
        padding: 10px 18px;
        border-top: 1px solid var(--qc-border);
        background: #FAFBFC;
    }
    .qc-pagination-bar .pagination { margin: 0; }
    .qc-pagination-bar .pagination li a {
        padding: 4px 10px;
        font-size: 13px;
        border-radius: 4px !important;
        margin: 0 1px;
        border: 1px solid var(--qc-border);
        color: var(--qc-primary);
    }
    .qc-pagination-bar .pagination li.active a {
        background: var(--qc-primary);
        border-color: var(--qc-primary);
        color: #fff;
    }
    .qc-pagination-info {
        font-size: 12px;
        color: var(--qc-muted);
        margin-left: 8px;
    }

    /* ── Loading ── */
    .qc-loading-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 40px;
        color: var(--qc-muted);
        font-size: 14px;
    }
    .qc-spinner {
        width: 22px; height: 22px;
        border: 3px solid #DCE8F5;
        border-top-color: var(--qc-primary);
        border-radius: 50%;
        animation: qc-spin .7s linear infinite;
    }
    @keyframes qc-spin { to { transform: rotate(360deg); } }

    /* ── 狀態圓點（表格內） ── */
    .circle_red, .circle_green, .circle_y, .circle_gray, .circle_orange {
        display: inline-block;
        vertical-align: middle;
        width: 10px; height: 10px;
        border-radius: 50%;
        margin-right: 4px;
        flex-shrink: 0;
    }
    .circle_red    { background: radial-gradient(circle, #e74c3c 30%, #c0392b 100%); }
    .circle_green  { background: radial-gradient(circle, #2ecc71 30%, #27ae60 100%); }
    .circle_y, .circle_orange { background: radial-gradient(circle, #f5a623 30%, #d4891c 100%); }
    .circle_gray   { background: radial-gradient(circle, #b0bec5 30%, #90a4ae 100%); }

    .qc-flex { display:flex; align-items:center; flex-wrap:wrap; gap:4px; }

    /* 容器 chip */
    .container-btn {
        background: #EBF3FB; color: #1868AE;
        border: none; border-radius: 4px;
        padding: 1px 6px; font-size: 11px; font-weight:600;
        display:inline-block; cursor:default; pointer-events:none;
    }

    /* 備註 chip */
    .qc-remark-chip {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 11px;
        margin-bottom: 2px;
    }
    .qc-remark-chip.qq  { background:#FFF3CD; color:#856404; }
    .qc-remark-chip.ok  { background:#D4EDDA; color:#155724; }
    .qc-remark-chip.gen { background:#F0F0F0; color:#555; }
    .qc-remark-chip.bom { background:#E8EAF6; color:#3949AB; }

    /* DataTables 覆蓋 */
    .dataTables_wrapper .dataTables_filter { display:none !important; } /* 搜尋用自訂框 */
    .dataTables_wrapper .dataTables_length { display:none !important; }
    .dataTables_wrapper .dataTables_info   { font-size:12px; color:var(--qc-muted); padding:0 18px 8px; }
    .dataTables_wrapper .dataTables_paginate { display:none !important; } /* 分頁用自訂 */
    .dt-buttons { display:none !important; } /* 隱藏 export 按鈕 */

    /* btn-copy */
    .btn-copy { background:#f0ad4e; color:#fff; border:none; padding:1px 3px; border-radius:3px; cursor:pointer; font-size:11px; vertical-align:middle; }
    .btn-copy:hover { background:#ec971f; }

    /* 異常單 chip（備註欄） */
    .qc-abnormal-order-chip {
        display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap;
        background: #FFF0F0; border: 1px solid #FFCCCC; border-radius: 4px;
        padding: 2px 6px; margin-top: 3px; font-size: 11px; color: #C0392B;
        cursor: pointer; width: 100%;
    }
    .qc-abnormal-order-chip:hover { background: #FFE0E0; }
    .qc-abnormal-order-chip strong { color: #922B21; }
    .qc-abnormal-order-chip .ao-status {
        background: #922B21; color: #fff; border-radius: 3px;
        padding: 0 4px; font-size: 10px; margin-left: 2px;
    }

    /* 異常 modal 開立異常單按鈕 */
    .btn-open-ncr {
        padding: 2px 7px; font-size: 11px; border-radius: 4px;
        background: #C0392B; color: #fff; border: none; cursor: pointer;
        white-space: nowrap;
    }
    .btn-open-ncr:hover { background: #922B21; }
    .btn-open-ncr.has-ncr { background: #7F8C8D; }

    /* QC完工 Drawer */
    .qc-drawer-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.35); z-index: 1040;
    }
    .qc-drawer {
        position: fixed; top: 60px; right: -520px; width: 500px;
        height: auto;
        max-height: calc(100vh - 80px);
        background: #fff; box-shadow: -4px 0 20px rgba(0,0,0,.15);
        z-index: 1050; transition: right .28s cubic-bezier(.4,0,.2,1);
        display: flex; flex-direction: column;
        border-radius: 8px 0 0 8px;
    }
    .qc-drawer.open { right: 0; }
    .qc-drawer-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 18px; background: var(--qc-primary); color: #fff; flex-shrink: 0;
    }
    .qc-drawer-header h5 { margin: 0; font-size: 15px; font-weight: 700; }
    .qc-drawer-close {
        background: none; border: none; color: #fff; font-size: 20px;
        cursor: pointer; opacity: .8; line-height: 1;
    }
    .qc-drawer-close:hover { opacity: 1; }
    .qc-drawer-body { flex: 1; overflow-y: auto; padding: 8px 16px; max-height: 650px; }
    .qc-completed-card {
        border: 1px solid #E4E9F0; border-radius: 6px; padding: 7px 10px;
        margin-bottom: 5px; font-size: 13px; background: #fff;
    }
    .qc-completed-card .cc-head {
        display: flex; justify-content: space-between; align-items: flex-start;
        margin-bottom: 4px;
    }
    .qc-completed-card .cc-bom { font-weight: 700; color: var(--qc-primary); font-size: 13px; }
    .qc-completed-card .cc-time { font-size: 11px; color: var(--qc-muted); white-space: nowrap; margin-left: 8px; }
    .qc-completed-card .cc-meta { color: #555; font-size: 12px; margin-top: 2px; }

    .btn-qc-completed-search {
        padding: 5px 12px; border: 1px solid var(--qc-border); border-radius: 20px;
        background: #fff; font-size: 12px; color: var(--qc-text); cursor: pointer;
        transition: all .15s; white-space: nowrap;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .btn-qc-completed-search:hover { border-color: #27AE60; color: #27AE60; background: #EAFAF1; }

    /* ── 統計按鈕 ── */
    .btn-qc-stats {
        padding: 5px 12px; border: 1px solid var(--qc-border); border-radius: 20px;
        background: #fff; font-size: 12px; color: var(--qc-text); cursor: pointer;
        transition: all .15s; white-space: nowrap;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .btn-qc-stats:hover { border-color: #8E44AD; color: #8E44AD; background: #F5EEF8; }

    /* ── 統計 Modal ── */
    #statsModal .modal-dialog { width: 860px; max-width: 96vw; }
    #statsModal .modal-header {
        background: linear-gradient(135deg, #2E6DA4 0%, #8E44AD 100%);
        color: #fff; border-radius: 6px 6px 0 0;
    }
    #statsModal .modal-title { font-size: 16px; font-weight: 700; }
    #statsModal .modal-header .close { color: #fff; opacity: .8; }
    #statsModal .modal-header .close:hover { opacity: 1; }

    /* 統計卡片 */
    .stats-cards { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
    .stats-card {
        flex: 1; min-width: 180px;
        border-radius: 10px; padding: 14px 18px;
        background: #fff; border: 1px solid #E4E9F0;
        box-shadow: 0 2px 6px rgba(0,0,0,.06);
    }
    .stats-card .sc-label { font-size: 11px; color: #8A9BB0; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; }
    .stats-card .sc-total { font-size: 30px; font-weight: 800; color: #2E6DA4; line-height: 1.1; margin: 4px 0 2px; }
    .stats-card .sc-row { display: flex; gap: 14px; font-size: 12px; margin-top: 4px; }
    .stats-card .sc-ok  { color: #27AE60; font-weight: 700; }
    .stats-card .sc-ng  { color: #E74C3C; font-weight: 700; }

    /* 趨勢圖 */
    .stats-section-title { font-size: 13px; font-weight: 700; color: #3D4B5C; margin: 0 0 8px; }
    .trend-chart-wrap { overflow-x: auto; padding-bottom: 4px; }
    .trend-chart { display: flex; align-items: flex-end; gap: 4px; height: 100px; }
    .trend-bar-group { display: flex; flex-direction: column; align-items: center; min-width: 22px; flex: 1; }
    .trend-bar-inner { display: flex; gap: 2px; align-items: flex-end; width: 100%; justify-content: center; }
    .trend-bar { border-radius: 3px 3px 0 0; min-height: 3px; width: 8px; }
    .trend-bar.ok { background: #27AE60; }
    .trend-bar.ng { background: #E74C3C; }
    .trend-label { font-size: 9px; color: #8A9BB0; margin-top: 3px; white-space: nowrap; }

    /* 製程統計 */
    .process-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 8px; }
    .process-table th { background: #F5F7FA; color: #8A9BB0; font-weight: 600; padding: 6px 8px; text-align: left; border-bottom: 2px solid #E4E9F0; }
    .process-table td { padding: 5px 8px; border-bottom: 1px solid #F0F3F7; vertical-align: middle; }
    .process-table tr:last-child td { border-bottom: none; }
    .process-bar-wrap { background: #F0F3F7; border-radius: 4px; height: 10px; overflow: hidden; min-width: 80px; }
    .process-bar-ok { background: #27AE60; height: 100%; border-radius: 4px 0 0 4px; display: inline-block; }
    .process-bar-ng { background: #E74C3C; height: 100%; border-radius: 0 4px 4px 0; display: inline-block; }

    /* 控制列 */
    .stats-control-bar {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        padding: 10px 20px; border-bottom: 1px solid #E4E9F0; background: #F9FAFB;
    }
    .stats-quick-tabs { display: flex; gap: 4px; }
    .stats-tab {
        padding: 4px 14px; border: 1px solid #E4E9F0; border-radius: 20px;
        background: #fff; font-size: 12px; font-weight: 600; color: #8A9BB0;
        cursor: pointer; transition: all .15s; white-space: nowrap;
    }
    .stats-tab.active { background: #2E6DA4; border-color: #2E6DA4; color: #fff; }
    .stats-tab:hover:not(.active) { border-color: #2E6DA4; color: #2E6DA4; }
    .stats-nav-arrow {
        padding: 2px 7px; border: 1px solid #E4E9F0; border-radius: 4px;
        background: #fff; font-size: 11px; color: #8A9BB0; cursor: pointer;
        line-height: 1.6; transition: all .15s;
    }
    .stats-nav-arrow:hover:not(:disabled) { border-color: #2E6DA4; color: #2E6DA4; background: #EAF2FB; }
    .stats-nav-arrow:disabled { opacity: 0.35; cursor: default; }
    #stats-custom-inputs input[type=date] {
        border: 1px solid #E4E9F0; border-radius: 6px; padding: 4px 8px;
        font-size: 13px; color: #3D4B5C; outline: none;
    }
    #stats-custom-inputs input[type=date]:focus { border-color: #2E6DA4; }

    /* 自訂區間（舊的，保留以免其他地方引用）*/
    .stats-custom-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .stats-custom-row input[type=date] {
        border: 1px solid #E4E9F0; border-radius: 6px; padding: 5px 8px;
        font-size: 13px; color: #3D4B5C; outline: none;
    }
    .stats-custom-row input[type=date]:focus { border-color: #2E6DA4; box-shadow: 0 0 0 2px rgba(46,109,164,.12); }
    .btn-stats-query {
        background: #2E6DA4; color: #fff; border: none; border-radius: 6px;
        padding: 5px 14px; font-size: 13px; cursor: pointer; transition: background .15s;
    }
    .btn-stats-query:hover { background: #1f4f7a; }
    .btn-stats-pdf {
        background: #E74C3C; color: #fff; border: none; border-radius: 6px;
        padding: 5px 14px; font-size: 13px; cursor: pointer; transition: background .15s;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .btn-stats-pdf:hover { background: #c0392b; }

    /* 列印樣式 */
    @media print {
        body > *:not(#stats-print-area) { display: none !important; }
        #stats-print-area { display: block !important; }
        .no-print { display: none !important; }
    }
    #stats-print-area { display: none; }
    </style>

    <title>QC 待驗</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">


    <!-- Datatables -->
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css" rel="stylesheet">
    <!-- 過長表格變+號 -->
    <!-- <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet"> -->
    <!-- <link href="../../resource/css/responsive.bootstrap.css" rel="stylesheet"> -->
    <!-- 日期選單用 -->
    <link rel="stylesheet" href="http://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css">

</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">

            <!-- side and top bar include -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>
            <!-- /side and top bar include -->

            <!-- page content -->
            <div class="right_col" role="main">
                <div class="">

                    <div class="page-title">
                        <div class="title_left">
                            <h4>
                                <?php
                                if (!empty($_GET['message'])) {
                                    if ($_GET['message'] == "success") {
                                        echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    報工成功
                                    </div>";
                                    } else if ($_GET['message'] == "updatesuccess") {
                                        echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    更新成功
                                    </div>";
                                    } else if ($_GET['message'] == "del") {
                                        echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    已刪除紀錄
                                    </div>";
                                    } else if ($_GET['message'] != "success") {
                                        $var = $_GET['message'];
                                        echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    $var
                                    </div>";
                                    }
                                }
                                ?>
                            </h4>
                            <!-- <h3>Event <small>Live</small></h3> -->
                        </div>
                        <div class="clearfix"></div>
                    </div>

                    <!-- 待驗清單主區 -->
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="qc-panel" id="qc-check-list-panel">

                                <!-- ── 頂部工具列 ── -->
                                <div class="qc-toolbar">
                                    <div class="qc-toolbar-left">
                                        <span class="qc-title">QC 待驗清單</span>
                                        <span class="qc-count-badge" id="qc-count-badge">載入中...</span>
                                        <button type="button" id="btn-add-custom"
                                                class="btn-qc-add"
                                                data-toggle="modal"
                                                data-target="#myModal_reply_custom">
                                            <i class="fa fa-plus"></i> 新增
                                        </button>
                                    </div>
                                    <div class="qc-toolbar-right">
                                        <!-- 統計 -->
                                        <button class="btn-qc-stats" onclick="openStatsModal()">
                                            <i class="fa fa-bar-chart" style="color:#8E44AD;"></i> 統計
                                        </button>
                                        <!-- QC完工查詢 -->
                                        <button class="btn-qc-completed-search" onclick="openCompletedDrawer()">
                                            <i class="fa fa-check-circle" style="color:#27AE60;"></i> QC完工紀錄
                                        </button>
                                        <!-- 搜尋框 -->
                                        <div class="qc-search-wrap">
                                            <i class="fa fa-search qc-search-icon"></i>
                                            <input type="text" id="qc-search-input"
                                                   class="qc-search-input"
                                                   placeholder="搜尋 BOM / 料號 / 客戶 / 廠商 / 製程">
                                            <span id="qc-search-clear" class="qc-search-clear" style="display:none;">×</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- ── 篩選列 ── -->
                                <div class="qc-filter-bar">
                                    <!-- 狀態篩選 -->
                                    <div class="qc-filter-group">
                                        <span class="qc-filter-label">狀態</span>
                                        <button class="qc-status-btn active" data-qc="all"    onclick="setQcFilter('all')">
                                            全部
                                        </button>
                                        <button class="qc-status-btn" data-qc="gray"   onclick="setQcFilter('gray')">
                                            <span class="dot dot-gray"></span>待驗
                                        </button>
                                        <button class="qc-status-btn" data-qc="qq"     onclick="setQcFilter('qq')">
                                            <span class="dot dot-yellow"></span>異常
                                        </button>
                                        <button class="qc-status-btn" data-qc="green"  onclick="setQcFilter('green')">
                                            <span class="dot dot-green"></span>允收
                                        </button>
                                    </div>

                                    <div class="qc-filter-divider"></div>

                                    <!-- 製程篩選 -->
                                    <div class="qc-filter-group" style="flex:1;flex-wrap:wrap;">
                                        <span class="qc-filter-label">製程</span>
                                        <div id="pti-filter-btns" style="display:inline-flex;flex-wrap:wrap;gap:4px;align-items:center;">
                                            <span style="color:#aaa;font-size:12px;">載入中...</span>
                                        </div>
                                    </div>

                                    <div class="qc-filter-divider"></div>

                                    <button class="btn-qc-reset" onclick="cancelFilters()" title="清除所有篩選">
                                        <i class="fa fa-times"></i> 重設
                                    </button>
                                </div>

                                <!-- ── 表格區 ── -->
                                <div class="qc-table-wrap">
                                    <table id="qc-data-table" class="table table-hover qc-table">
                                        <thead>
                                            <tr>
                                                <th hidden>id</th>
                                                <th class="col-status">狀態</th>
                                                <th class="col-client">客戶</th>
                                                <th class="col-bom">BOM</th>
                                                <th class="col-part">料號</th>
                                                <th class="col-date">回廠</th>
                                                <th class="col-process">製程</th>
                                                <th class="col-maker">廠商</th>
                                                <th class="col-qty">數量</th>
                                                <th class="col-container">容器</th>
                                                <th class="col-remark">備註</th>
                                                <th class="col-action">操作</th>
                                                <th hidden>pti</th>
                                                <th hidden class="never">qc_raw</th>
                                            </tr>
                                        </thead>
                                        <tbody id="qc-tbody">
                                            <!-- 骨架載入動畫 -->
                                            <tr class="qc-skeleton-row">
                                                <td colspan="12">
                                                    <div class="qc-loading-wrap">
                                                        <div class="qc-spinner"></div>
                                                        <span>載入中...</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- ── 分頁列 ── -->
                                <div id="qc-pagination" class="qc-pagination-bar"></div>

                            </div><!-- /.qc-panel -->
                        </div>
                    </div>

                    <!-- Container for Modals -->
                    <div id="modals-container"></div>

                    <!-- ── 統計 Modal ── -->
                    <div class="modal fade" id="statsModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    <h4 class="modal-title"><i class="fa fa-bar-chart"></i> QC 檢驗統計</h4>
                                </div>
                                <!-- ── 控制列（頂部）── -->
                                <div class="stats-control-bar no-print">
                                    <!-- 快速切換 -->
                                    <div class="stats-quick-tabs">
                                        <button class="stats-nav-arrow" onclick="navigateDay(-1)" title="前一天">&#9664;</button>
                                        <button class="stats-tab active" data-tab="today" id="stats-today-btn" onclick="switchStatsTab('today')">今天</button>
                                        <button class="stats-nav-arrow" id="stats-nav-next" onclick="navigateDay(1)" title="後一天" disabled>&#9654;</button>
                                        <button class="stats-tab"        data-tab="week"   onclick="switchStatsTab('week')">本週</button>
                                        <button class="stats-tab"        data-tab="month"  onclick="switchStatsTab('month')">本月</button>
                                        <button class="stats-tab"        data-tab="custom" onclick="switchStatsTab('custom')">自訂</button>
                                    </div>
                                    <!-- 自訂區間（預設隱藏）-->
                                    <div id="stats-custom-inputs" style="display:none;align-items:center;gap:6px;flex-wrap:wrap;">
                                        <input type="date" id="stats-date-from">
                                        <span style="font-size:12px;color:#8A9BB0;">至</span>
                                        <input type="date" id="stats-date-to">
                                        <button class="btn-stats-query" onclick="queryCustomStats()">查詢</button>
                                    </div>
                                    <!-- 列印 -->
                                    <button class="btn-stats-pdf" onclick="printStats()" style="margin-left:auto;">
                                        <i class="fa fa-print"></i> 列印/PDF
                                    </button>
                                </div>
                                <div class="modal-body" id="stats-modal-body" style="padding:16px 20px;">
                                    <div style="text-align:center;padding:40px;color:#8A9BB0;"><i class="fa fa-spinner fa-spin fa-2x"></i><br>載入中...</div>
                                </div>
                                <div class="modal-footer no-print" style="text-align:right;">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 列印區（隱藏，print 時顯示）-->
                    <div id="stats-print-area"></div>

                    <!-- 新增報工 Modal -->
                    <div id="myModal_reply_custom" class="modal fade" role="dialog">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal">×</button>
                                    <h4 class="modal-title">新增報工（未在列表上）</h4>
                                </div>
                                <div class="modal-body">
                                    <table class="table table-condensed" style="margin-bottom:0;">
                                        <tr>
                                            <td style="width:90px;vertical-align:middle;">BOM查詢</td>
                                            <td>
                                                <div style="display:flex;gap:6px;align-items:center;">
                                                    <input type="text" name="bom_query" id="input-bom-query"
                                                           class="form-control input-sm"
                                                           style="width:155px;"
                                                           maxlength="12" pattern="^B-\d{10}$"
                                                           placeholder="B-1234567890">
                                                    <button type="button" id="btn-bom-update"
                                                            class="btn btn-primary btn-sm">更新</button>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:middle;">客戶</td>
                                            <td><input name="clientName" type="text" class="form-control input-sm" style="width:140px;" readonly></td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:middle;">料號</td>
                                            <td>
                                                <div style="display:flex;gap:6px;align-items:center;">
                                                    <input name="dId" type="text" class="form-control input-sm" style="width:66%;" readonly>
                                                    <button type="button" id="btn-custom-qrcode" class="btn btn-default btn-sm" style="display:none;padding:1px 8px;" title="顯示QR Code"><i class="fa fa-qrcode" style="font-size:1.2em;"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="vertical-align:middle;">製程</td>
                                            <td>
                                                <select name="bom_ing_fid" id="select-bom-ing"
                                                        class="form-control input-sm"
                                                        style="width:auto;min-width:200px;font-family:'Courier New',monospace;">
                                                    <option value="">請先輸入 BOM 後點更新</option>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr class="custom-modal-options" style="display:none;">
                                            <td style="vertical-align:middle;">操作</td>
                                            <td>
                                                <div class="qc-action-group">
                                                    <button type="button" class="btn btn-warning btn-sm btn-option-abnormal">異常</button>
                                                    <button type="button" class="btn btn-success btn-sm btn-option-accept">允收</button>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
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
    </div>

    <!-- QC Remark Popup -->
    <div id="qcRemarkPopup" style="display:none; 
                                 position:absolute; 
                                 border:1px solid #ccc; 
                                 background-color:white; 
                                 padding: 1em 10px 10px 10px; /* 修改：頂部padding為1em，其他方向10px */
                                 z-index:1050; 
                                 box-shadow: 0 0 10px rgba(0,0,0,0.1); 
                                 min-width: 150px; /* Adjusted: Minimum width */
                                 /* max-width: 300px; Removed: To allow content-driven width */
                                 white-space: pre-wrap; 
                                 word-wrap: break-word;">
        <div id="qcRemarkPopupContent" style="max-height: 150px; /* Adjusted: Max height for content before scroll */
                                           overflow-y: auto;
                                           text-align: left; /* 新增：確保文字靠左對齊 */"></div>
    </div>
    <!-- End QC Remark Popup -->

    <!-- jQuery -->
    <script src="../../resource/js/jquery.min.js"></script>
    <!-- jQuery UI (for Datepicker and other UI widgets) - Moved BEFORE Bootstrap -->
    <script src="http://code.jquery.com/ui/1.11.4/jquery-ui.js"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js"></script>
    <!-- FastClick -->
    <script src="../../resource/js/fastclick.js"></script>
    <!-- NProgress -->
    <script src="../../resource/js/nprogress.js"></script>
    <!-- iCheck -->
    <script src="../../resource/js/icheck.min.js"></script>
    <!-- Datatables -->
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <script src="../../resource/js/dataTables.buttons.min.js"></script>
    <script src="../../resource/js/buttons.bootstrap.min.js"></script>
    <script src="../../resource/js/buttons.flash.min.js"></script>
    <script src="../../resource/js/buttons.html5.min.js"></script>
    <script src="../../resource/js/buttons.print.min.js"></script>
    <script src="../../resource/js/dataTables.fixedHeader.min.js"></script>
    <script src="../../resource/js/dataTables.keyTable.min.js"></script>
    <script src="../../resource/js/dataTables.responsive.min.js"></script>
    <script src="../../resource/js/responsive.bootstrap.js"></script>
    <script src="../../resource/js/dataTables.scroller.min.js"></script>
    <script src="../../resource/js/jszip.min.js"></script>
    <script src="../../resource/js/pdfmake.min.js"></script>
    <script src="../../resource/js/vfs_fonts.js"></script>
    <!-- Custom Theme Scripts -->
    <!-- 在 custom.min.js 載入前先隱藏我們的表格，防止被自動初始化 -->
    <script>
        // 同步執行：把 qc-data-table 暫時改名並移除 .table class
        // custom.min.js 用 class=.table 或 id 來找表格，兩個都擋掉
        var _qcTableEl = document.getElementById('qc-data-table');
        if (_qcTableEl) {
            _qcTableEl.id = '__qc_dt_hidden__';
            _qcTableEl.className = _qcTableEl.className.replace(/\btable\b/g, '');
        }
    </script>
    <script src="../../resource/js/custom.min.js"></script>
    <script>
        // custom.min.js 執行完後還原
        var _qcTableHidden = document.getElementById('__qc_dt_hidden__');
        if (_qcTableHidden) {
            _qcTableHidden.id = 'qc-data-table';
            if (_qcTableHidden.className.indexOf('table') === -1) {
                _qcTableHidden.className = 'table table-hover qc-table';
            }
        }
    </script>

    <!-- Embed PHP data into JavaScript -->
    <script>
        var allRawData = []; // 資料由 AJAX 分頁載入，不再一次性嵌入
        var currentUserId = <?php echo json_encode($id ?? null); ?>;
        var currentUserStatus = <?php echo json_encode($user_status ?? null); ?>;
        console.log('[QC_check_list.php] JavaScript loaded and executing.');
        var initialNgTxtList = <?php echo json_encode($ng_txt_list ?? []); ?>;

        // 分頁狀態
        var qcPagination = {
            page: 1,
            perPage: 10,
            totalPages: 1,
            totalRecords: 0,
            filterPTI: '',
            filterQC: 'all',
            filterSearch: '',
            loading: false
        };
    </script>
    <script>
        // Global auto-update control variables
        var autoUpdatePaused = false;
        var autoUpdateIntervalId;
        const AUTO_UPDATE_INTERVAL_MS = 5000; // 5 seconds

        var customBomData = null; // ⭐ 新增：用於暫存「新增」彈窗中查詢的BOM資料
        // Global DataTable instance and column index
        var dataTableInstance;

        /**
         * Checks if any of the specified QC modals are currently open.
         * @returns {boolean} True if a modal is open, false otherwise.
         */
        function isAnyModalOpen() {
            // Check for any modal with an ID starting with myModal_qq_, myModal_ok_, myModal_aod_, myModal_ng_, or myModal_qrcode_
            // that has the 'in' class (Bootstrap 3) or 'show' class (Bootstrap 4/5)
            return $('.modal[id^="myModal_qq_"], .modal[id^="myModal_ok_"], .modal[id^="myModal_aod_"], .modal[id^="myModal_ng_"], .modal[id^="myModal_qrcode_"]').is('.in, .show');
        }

        /**
         * 載入指定頁資料（初始載入、翻頁、篩選時呼叫）
         */
        function fetchAndUpdateData(page, opts) {
            if (qcPagination.loading) return;
            // DataTable 尚未初始化時不執行（由 ready 結尾再呼叫）
            if (!dataTableInstance) return;

            page = page || qcPagination.page;
            opts = opts || {};

            if (opts.pti    !== undefined) qcPagination.filterPTI    = opts.pti;
            if (opts.qc     !== undefined) qcPagination.filterQC     = opts.qc;
            if (opts.search !== undefined) qcPagination.filterSearch = opts.search;

            if (autoUpdatePaused || isAnyModalOpen()) return;

            qcPagination.loading = true;
            qcPagination.page    = page;

            $.ajax({
                url: '../../src/store/_fetch_qc_data.php',
                type: 'GET',
                dataType: 'json',
                data: {
                    page:    qcPagination.page,
                    perPage: qcPagination.perPage,
                    pti:     qcPagination.filterPTI,
                    qc:      qcPagination.filterQC,
                    search:  qcPagination.filterSearch
                },
                success: function(response) {
                    qcPagination.loading = false;
                    if (response.success && response.data) {
                        qcPagination.totalPages   = response.totalPages;
                        qcPagination.totalRecords = response.totalRecords;
                        window.allRawData = response.data;
                        if (typeof window.populateTableWithData === 'function') {
                            window.populateTableWithData(window.allRawData);
                        }
                        renderQcPagination();
                        // 第一頁時動態渲染篩選按鈕
                        if (response.presentPTI && response.presentPTI.length) {
                            renderPtiButtons(response.presentPTI);
                        }
                    } else {
                        console.error('[QC] 資料載入失敗:', response.message);
                    }
                },
                error: function(jqXHR, textStatus) {
                    qcPagination.loading = false;
                    console.error('[QC] AJAX 失敗:', textStatus);
                }
            });
        }

        // 製程篩選按鈕
        var ptiButtonsRendered = false;
        var currentActivePTI   = '';
        var allProcessTypesMap = {
            '138':'客供料','1':'車床','2':'銑床','3':'拉串插栓槽',
            '4':'滾插切齒','66':'滾研栓槽','59':'倒圓尖角','7':'熱處理',
            '8':'線割','10':'平研','11':'外研','911':'孔外研','9':'孔平研',
            '33':'研磨','164':'回客戶','12':'齒研','16':'雷刻包裝',
            '189':'其他製程','202':'全製'
        };
        var ptiButtonOrder = ['138','1','2','3','4','66','59','7','8','10','11','911','9','33','164','12','16','189','202'];
        var ptiSeps = {'7':true,'16':true};

        function renderPtiButtons(presentPTI) {
            if (ptiButtonsRendered) return;
            ptiButtonsRendered = true;
            var $wrap = $('#pti-filter-btns').empty();
            for (var i=0;i<ptiButtonOrder.length;i++) {
                var pti = ptiButtonOrder[i];
                if (presentPTI.indexOf(pti)===-1 && presentPTI.indexOf(parseInt(pti))===-1) continue;
                if (!allProcessTypesMap[pti]) continue;
                $wrap.append('<button class="qc-pti-btn" data-pti="'+pti+'" onclick="setPtiFilter(\''+pti+'\')">'+allProcessTypesMap[pti]+'</button>');
                if (ptiSeps[pti]) {
                    var hasMore=false;
                    for (var j=i+1;j<ptiButtonOrder.length;j++) {
                        var np=ptiButtonOrder[j];
                        if ((presentPTI.indexOf(np)!==-1||presentPTI.indexOf(parseInt(np))!==-1)&&allProcessTypesMap[np]){hasMore=true;break;}
                    }
                    if (hasMore) $wrap.append('<span class="qc-pti-sep">|</span>');
                }
            }
        }

        function setPtiFilter(pti) {
            if (currentActivePTI === pti) {
                currentActivePTI = '';
                $('.qc-pti-btn').removeClass('active');
                fetchAndUpdateData(1, { pti: '' });
            } else {
                currentActivePTI = pti;
                $('.qc-pti-btn').removeClass('active');
                $('.qc-pti-btn[data-pti="'+pti+'"]').addClass('active');
                fetchAndUpdateData(1, { pti: pti });
            }
        }

        function filterByPTI(ptiValue) {
            if (!ptiValue) {
                currentActivePTI = '';
                $('.qc-pti-btn').removeClass('active');
                fetchAndUpdateData(1, { pti: '' });
            } else {
                setPtiFilter(ptiValue);
            }
        }

        function setQcFilter(qcVal) {
            currentQcCheckFilter = qcVal;
            $('.qc-status-btn').removeClass('active');
            $('.qc-status-btn[data-qc="'+qcVal+'"]').addClass('active');
            fetchAndUpdateData(1, { qc: qcVal });
        }

        function toggleQcCheckFilter() {
            var order=['all','gray','qq','green'];
            var next = order[(order.indexOf(currentQcCheckFilter)+1)%order.length];
            setQcFilter(next);
        }

        function cancelFilters() {
            currentActivePTI = '';
            currentQcCheckFilter = 'all';
            $('.qc-pti-btn').removeClass('active');
            $('.qc-status-btn').removeClass('active');
            $('.qc-status-btn[data-qc="all"]').addClass('active');
            $('#qc-search-input').val('');
            $('#qc-search-clear').hide();
            fetchAndUpdateData(1, { pti:'', qc:'all', search:'' });
        }

        /**
         * 渲染分頁控制列
         */
        function renderQcPagination() {
            var p = qcPagination;
            $('#qc-count-badge').text('共 ' + p.totalRecords + ' 筆');
            var $bar = $('#qc-pagination').empty();
            if (p.totalPages <= 1) return;
            var html = '<nav><ul class="pagination">';
            html += '<li'+(p.page<=1?' class="disabled"':'')+'><a href="#" onclick="qcGoPage('+(p.page-1)+');return false;">«</a></li>';
            var s=Math.max(1,p.page-2),e=Math.min(p.totalPages,p.page+2);
            if(s>1){html+='<li><a href="#" onclick="qcGoPage(1);return false;">1</a></li>';if(s>2)html+='<li class="disabled"><a>…</a></li>';}
            for(var i=s;i<=e;i++) html+='<li'+(i===p.page?' class="active"':'')+'><a href="#" onclick="qcGoPage('+i+');return false;">'+i+'</a></li>';
            if(e<p.totalPages){if(e<p.totalPages-1)html+='<li class="disabled"><a>…</a></li>';html+='<li><a href="#" onclick="qcGoPage('+p.totalPages+');return false;">'+p.totalPages+'</a></li>';}
            html+='<li'+(p.page>=p.totalPages?' class="disabled"':'')+'><a href="#" onclick="qcGoPage('+(p.page+1)+');return false;">»</a></li>';
            html+='</ul></nav><span class="qc-pagination-info">第 '+p.page+' / '+p.totalPages+' 頁</span>';
            $bar.html(html);
        }

        function qcGoPage(page) {
            if (page<1||page>qcPagination.totalPages||page===qcPagination.page) return;
            fetchAndUpdateData(page);
        }

        const ptiColumnIndex = 12; // Column index for 'process_type_id' (0-based)
        var currentQcCheckFilter = "all"; // 'all', 'gray', 'red', 'yellow', 'green'

        // 彈跳視窗
        function he(str) {
            if (str === null || typeof str === 'undefined') return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function generatePsHtml(item) {
            // --- GEMINI CODE ASSIST: Modify generatePsHtml for remark order ---
            let psHtml = '';
            let qqEntries = [];
            let okEntries = [];

            // 1. Append general bom_ing.ps remark first if it exists
            if (item.ps && item.ps.trim() !== '') {
                psHtml += `<div style="padding: 2px 5px; margin-bottom: 2px;">${he(item.ps.trim())}</div>`;
            }

            if (item.individual_qc_entries && Array.isArray(item.individual_qc_entries) && item.individual_qc_entries.length > 0) {
                // 分離 QQ 和 OK 記錄
                item.individual_qc_entries.forEach(function(entry) {
                    if (entry.QC_check === 'QQ') {
                        qqEntries.push(entry);
                    } else if (entry.QC_check === 'ok') {
                        okEntries.push(entry);
                    }
                });

                // 2. Render QQ (異常) records (already sorted by date DESC from backend)
                // 記錄已經由後端 SQL 依照日期排序 (新的在前)
                qqEntries.forEach(function(check_entry) {
                    var entryDate = he(check_entry.QC_check_date_formatted || check_entry.QC_check_date || '');
                    var entryRemarkText = he(check_entry.QC_ps || ''); // QQ 的備註欄位是 QC_ps
                    var entryQtyValue = parseFloat(check_entry.QC_QQ_sqty || 0);
                    var entryQtyDisplay = he(check_entry.QC_QQ_sqty || '0');
                    var bgColor = '#fff3cd'; // Yellowish for abnormal
                    var textColor = '#856404';

                    if (entryRemarkText.trim() !== '' || entryQtyValue !== 0 || entryQtyDisplay === '0') {
                        let line = '';
                        if (entryDate) {
                            line = entryDate + ' x' + entryQtyDisplay;
                            if (entryRemarkText.trim() !== '') {
                                line += ' ' + entryRemarkText;
                            }
                        } else {
                            if (entryQtyValue !== 0 || entryQtyDisplay === '0') {
                                line = 'x' + entryQtyDisplay;
                                if (entryRemarkText.trim() !== '') {
                                    line += ' ' + entryRemarkText;
                                }
                            } else {
                                line = entryRemarkText;
                            }
                        }
                        if (line.trim()) {
                            psHtml += `<div style="background-color: ${bgColor}; color: ${textColor}; padding: 2px 5px; margin-bottom: 2px; border-radius: 3px;">${line}</div>`;
                        }
                    }
                });

                // 3. Render OK (允收) records (already sorted by date DESC from backend)
                okEntries.forEach(function(check_entry) {
                    var entryDate = he(check_entry.QC_check_date_formatted || check_entry.QC_check_date || '');
                    var entryRemarkText = he(check_entry.QC_ps_ok || '');
                    var entryQtyValue = parseFloat(check_entry.QC_ok_sqty || 0);
                    var entryQtyDisplay = he(check_entry.QC_ok_sqty || '0');
                    var bgColor = '#d4edda';
                    var textColor = '#155724';

                    // ⭐⭐⭐ 新增 chip ⭐⭐⭐
                    let chipStr = '';
                    if (check_entry.QC_ps && check_entry.QC_ps.trim() !== '')
                        chipStr += `<span class="qc-chip qc-chip-blue">${he(check_entry.QC_ps)}</span>`;
                    if (check_entry.QC_ps2 && check_entry.QC_ps2.trim() !== '')
                        chipStr += `<span class="qc-chip qc-chip-blue">${he(check_entry.QC_ps2)}</span>`;

                    let line = '';
                    if (entryDate) {
                        line = entryDate + ' x' + entryQtyDisplay;
                        if (entryRemarkText.trim() !== '') {
                            line += ' ' + entryRemarkText;
                        }
                    } else {
                        if (entryQtyValue !== 0 || entryQtyDisplay === '0') {
                            line = 'x' + entryQtyDisplay;
                            if (entryRemarkText.trim() !== '') {
                                line += ' ' + entryRemarkText;
                            }
                        } else {
                            line = entryRemarkText;
                        }
                    }
                    if (line.trim() || chipStr) {
                        psHtml += `<div style="background-color: ${bgColor}; color: ${textColor}; padding: 2px 5px; margin-bottom: 2px; border-radius: 3px;">
            ${chipStr}${line}
        </div>`;
                    }
                });

            }
            // --- END GEMINI CODE ASSIST: Modify generatePsHtml for remark order ---

            // 異常單資訊（顯示在備註欄最下方）
            if (item.qa_abnormal_orders && item.qa_abnormal_orders.length > 0) {
                item.qa_abnormal_orders.forEach(function(ao) {
                    var statusTxt = ao.flow_dept ? '→ ' + he(ao.flow_dept) : (ao.is_closed ? '已結案' : '進行中');
                    psHtml += `<div class="qc-abnormal-order-chip" onclick="openQCQADetailModal(${he(ao.id)})" title="點擊查看詳情">
                        <i class="fa fa-file-text-o"></i>
                        <strong>${he(ao.abnormal_order_no)}</strong>
                        <span>${he(ao.responsible_unit || '-')}</span>
                        <span class="ao-status">${statusTxt}</span>
                    </div>`;
                });
            } else if (item.qa_abnormal_id) {
                // 相容舊的單一異常單欄位
                var aoStatus = item.qa_flow_dept ? '→ ' + he(item.qa_flow_dept) : '進行中';
                psHtml += `<div class="qc-abnormal-order-chip" onclick="openQCQADetailModal(${he(item.qa_abnormal_id)})" title="點擊查看詳情">
                    <i class="fa fa-file-text-o"></i>
                    <strong>${he(item.abnormal_order_no || '')}</strong>
                    <span>${he(item.qa_responsible_unit || '-')}</span>
                    <span class="ao-status">${aoStatus}</span>
                </div>`;
            }

            return psHtml;
        }

        function generateStatusHtml(item, primaryResponse) {
            // This function will be defined later, using primaryResponse data
            // For now, the logic inside the success callback handles this directly.
            // It's kept here as a placeholder if you want to centralize it.
            // The current implementation in the success callback is more direct.
            return ""; // Placeholder
        }

        function ShowModal(id) {
            var modal = document.getElementById(id);
            modal.style.display = "block";
        };

        // --- Helper function to update QC_ps button tooltip ---
        // Ensure this function is defined before updateTableRowDOM or globally accessible
        function updateQcPsButton(row, qcPs, qcPs2, qcPsAod) {
            // Target the "料號" cell, which is the 4th visible <td> (index 3)
            var qcNoteCell = row.find('td:eq(3)');
            var qcNoteButton = qcNoteCell.find('button.qc-remark-button'); // Specific class for the button
            var tooltipLines = [];

            var qcPsText = (qcPs && typeof qcPs === 'string') ? qcPs.trim() : '';
            var qcPs2Text = (qcPs2 && typeof qcPs2 === 'string') ? qcPs2.trim() : '';
            var qcPsAodText = (qcPsAod && typeof qcPsAod === 'string') ? qcPsAod.trim() : '';

            if (qcPsAodText !== '') {
                tooltipLines.push("特採：" + he(qcPsAodText));
            }
            if (qcPsText !== '') {
                tooltipLines.push("異常：" + he(qcPsText));
            }
            if (qcPs2Text !== '') {
                tooltipLines.push("驗退：" + he(qcPs2Text));
            }

            var finalTooltipTitle = tooltipLines.join('\n');

            if (finalTooltipTitle !== '') {
                if (qcNoteButton.length === 0) {
                    var $newButton = $('<button type="button" class="btn btn-xs btn-default qc-remark-button" data-toggle="tooltip" data-placement="right"></button>')
                        .attr('title', finalTooltipTitle)
                        .attr('data-remark-content', finalTooltipTitle) // Store for popup
                        .text('QC備註');
                    // Append next to the existing content, typically an <a> tag for d_id
                    qcNoteCell.find('a').first().append(' ').append($newButton);
                    $newButton.tooltip(); // Initialize Bootstrap tooltip
                } else {
                    qcNoteButton.attr('title', finalTooltipTitle).attr('data-remark-content', finalTooltipTitle).tooltip('fixTitle');
                }
            } else {
                if (qcNoteButton.length > 0) {
                    qcNoteButton.tooltip('destroy').remove();
                }
            }
        }

        // --- GEMINI CODE ASSIST: STEP 4 (Revised again for clarity and robustness) ---
        function updateTableRowDOM($targetRow, latestData) {
            if (!$targetRow || $targetRow.length === 0 || !latestData) {
                console.error("updateTableRowDOM: Invalid target row or data.");
                return;
            }

            var bomIngDetails = latestData.bom_ing_details;
            var individualQcEntries = latestData.individual_qc_entries || [];
            var totalQqQty = parseFloat(latestData.total_qq_qty) || 0;
            var totalOkQty = parseFloat(latestData.total_ok_qty) || 0;
            var mainTotalQty = parseFloat(bomIngDetails.sqty) || 0;

            // 1. Update Status Cell (Assume this is the 2nd visible column, index 1 for DataTables)
            var statusHtml = '';
            var qcCheck = bomIngDetails.QC_check ? bomIngDetails.QC_check.trim() : '';
            // Date from bom_ing table, used for 'ng' or 'AOD' states
            var qcCheckDateForOverride = he(bomIngDetails.QC_check_date_formatted || '');

            // Specific latest dates from qc_check table for 'QQ' and 'ok' entries
            var latestQqDateFormatted = he(latestData.latest_QQ_date_formatted || '');
            var latestOkDateFormatted = he(latestData.latest_ok_date_formatted || '');

            var statusParts = [];
            if (qcCheck === "ng") {
                statusHtml = `<div class="qc-flex"><span class="circle_red"></span><small>${qcCheckDateForOverride}</small></div>`;
            } else if (qcCheck === "ok") {
                // ⭐ 新增：當 bom_ing.QC_check 為 'ok' 時，直接顯示綠燈與日期
                statusHtml = `<div class="qc-flex"><span class="circle_green"></span><small>${qcCheckDateForOverride}</small></div>`;
            } else if (qcCheck === "AOD") {
                statusHtml = `<div class="qc-flex"><span class="circle_y"></span><small>${qcCheckDateForOverride}</small></div>`;
            } else {
                var totalCheckedQty = totalQqQty + totalOkQty;
                if (mainTotalQty > 0 && totalCheckedQty >= mainTotalQty) {
                    // Case 1: Fully checked (or over-checked) against a non-zero order quantity
                    if (totalQqQty > 0 && totalOkQty > 0) {
                        statusParts.push(`<span class="circle_y"></span><small>${he(String(totalQqQty))}</small>`);
                        statusParts.push(`<span class="circle_green"></span><small>${he(String(totalOkQty))}</small>`);
                    } else if (totalQqQty > 0) {
                        statusParts.push(`<span class="circle_y"></span><small>${latestQqDateFormatted}</small>`);
                    } else if (totalOkQty > 0) {
                        statusParts.push(`<span class="circle_green"></span><small>${latestOkDateFormatted}</small>`);
                    } else {
                        // Fully checked but no QQ/OK quantities (e.g., mainTotalQty is 0, or data inconsistency)
                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                    }
                } else {
                    // Case 2: Partially checked, OR mainTotalQty is 0, OR no items checked yet
                    if (mainTotalQty > 0 && totalCheckedQty < mainTotalQty && totalCheckedQty > 0) {
                        // Partially checked (some items are checked, but not all of a non-zero order)
                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                    }
                    // Always show QQ and OK quantities if they exist, regardless of partial/full status,
                    // unless it's a fully checked scenario handled above (where dates might be shown instead of qty).
                    if (totalQqQty > 0) {
                        statusParts.push(`<span class="circle_y"></span><small>${he(String(totalQqQty))}</small>`);
                    }
                    if (totalOkQty > 0) {
                        statusParts.push(`<span class="circle_green"></span><small>${he(String(totalOkQty))}</small>`);
                    }
                    // If after all checks, no status parts were added (e.g., 0 order qty, or 0 checked for positive order qty)
                    if (statusParts.length === 0) {
                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                    }
                }
                statusHtml = `<div class="qc-flex">${statusParts.join('&emsp;')}</div>`;
            }
            // Corrected: Status is the 1st visible column (td:eq(0))
            window.dataTableInstance.cell($targetRow.find('td:eq(0)')).data(statusHtml);
            $targetRow.attr('data-qc-check', qcCheck || ''); // Update the raw QC_check status attribute on the TR for filtering

            // 2. 更新容器 Cell (第 10 個可見欄位，DataTables 索引為 9)
            var containerHtml = '<div class="container-cell">';
            // ⭐ 修正點 1: 從 bomIngDetails 中讀取容器資料
            if (bomIngDetails.BIQC_ps && bomIngDetails.BIQC_ps.trim()) {
                containerHtml += `<button type="button" class="container-btn">${he(bomIngDetails.BIQC_ps)}</button>`;
            }
            if (bomIngDetails.BIQC_ps2 && bomIngDetails.BIQC_ps2.trim()) {
                containerHtml += `<button type="button" class="container-btn">${he(bomIngDetails.BIQC_ps2)}</button>`;
            }
            containerHtml += '</div>';
            // ⭐ 修正點 2: 使用正確的索引 9 來更新「容器」欄位
            window.dataTableInstance.cell($targetRow.find('td:eq(8)')).data(containerHtml);

            // 3. 更新備註 Cell (第 11 個可見欄位，DataTables 索引為 10)
            let tempItemForPs = {
                individual_qc_entries: individualQcEntries,
                ps: bomIngDetails.ps // General remark from bom_ing (bom_ing.ps)
            };
            let newPsHtml = generatePsHtml(tempItemForPs);

            // ⭐ 修正點 3: 使用正確的索引 10 來更新「備註」欄位-正確應該是使用欄位9
            window.dataTableInstance.cell($targetRow.find('td:eq(9)')).data(newPsHtml);

            // 4. 更新 QC備註 button tooltip (位於料號 cell)
            if (totalQqQty > 0) {
                $targetRow.attr('data-has-qq', 'true');
            } else {
                $targetRow.removeAttr('data-has-qq');
            }

            if (totalOkQty > 0) {
                $targetRow.attr('data-has-ok', 'true');
            } else {
                $targetRow.removeAttr('data-has-ok');
            }

            $targetRow.removeAttr('data-is-pending'); // Clear first
            if (qcCheck !== 'ng' && qcCheck !== 'AOD') {
                // A row is pending if it's not in a final state and not fully checked, or has 0 total quantity.
                if (mainTotalQty > 0 && totalCheckedQty < mainTotalQty || totalCheckedQty === 0) {
                    $targetRow.attr('data-is-pending', 'true');
                }
            }

            // 3. Update QC備註 button tooltip (located in 料號 cell, 4th visible column, td:eq(3))

            updateQcPsButton($targetRow, bomIngDetails.QC_ps, bomIngDetails.QC_ps2, bomIngDetails.QC_ps_aod);

            // 4. Invalidate DataTables row to re-read from data source and redraw
            window.dataTableInstance.row($targetRow).invalidate('data').draw(false);
        }
        // --- END GEMINI CODE ASSIST: STEP 4 (Revised again for clarity and robustness) ---


        // --- Copy to Clipboard Function ---
        function copyText(text) { // This existing function remains untouched
            if (!navigator.clipboard) {
                // Fallback for older browsers or insecure contexts
                try {
                    var textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.style.position = "fixed"; // Prevent scrolling to bottom
                    textArea.style.opacity = 0; // Make it invisible
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    // alert('已複製到剪貼簿 (Fallback): ' + text); // 移除提示
                } catch (err) {
                    console.error('Fallback: Oops, unable to copy', err);
                    alert('複製失敗 (Fallback)!');
                }
                return;
            }
            navigator.clipboard.writeText(text).then(function() {
                // alert('已複製到剪貼簿: ' + text); // 移除提示
            }).catch(function(err) {
                console.error('無法複製文字: ', err);
                alert('複製失敗! 請檢查瀏覽器控制台以獲取更多資訊。\n\n可能是因為頁面不是透過 HTTPS 或 localhost 訪問。');
            });
        }

        // --- New Copy to Clipboard Function with Icon Toggle ---
        function egSystemCopyTextAndToggleIcon(text, buttonElement) {
            if (!buttonElement) {
                console.error("Button element not provided to egSystemCopyTextAndToggleIcon.");
                // Fallback to simple copy if buttonElement is missing, or just copy text
                copyText(text); // Call the original simple copy function
                return;
            }

            var originalIconHtml = buttonElement.innerHTML; // Should be <i class="fa fa-copy"></i>
            buttonElement.innerHTML = '<i class="fa fa-check"></i>'; // Change to checkmark

            var revertIcon = function() {
                if (buttonElement) { // Check again in case button is removed from DOM
                    buttonElement.innerHTML = originalIconHtml;
                }
            };

            if (!navigator.clipboard) {
                try {
                    var textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.style.position = "fixed";
                    textArea.style.opacity = 0;
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    setTimeout(revertIcon, 1500); // Revert icon after 1.5 seconds
                } catch (err) {
                    console.error('Fallback: Oops, unable to copy', err);
                    alert('複製失敗 (Fallback)!');
                    revertIcon(); // Revert icon immediately on error
                }
                return;
            }
            navigator.clipboard.writeText(text).then(function() {
                setTimeout(revertIcon, 1500); // Revert icon after 1.5 seconds
            }).catch(function(err) {
                console.error('無法複製文字: ', err);
                alert('複製失敗! 請檢查瀏覽器控制台以獲取更多資訊。\n\n可能是因為頁面不是透過 HTTPS 或 localhost 訪問。');
                revertIcon(); // Revert icon immediately on error
            });
        }



        // --- Filtering Functions ---
        function filterByPTI(ptiValue) {
            fetchAndUpdateData(1, { pti: ptiValue || '' });
        }

        function toggleQcCheckFilter() {
            const contentSpan = document.getElementById("qcCheckColorContent");
            const figureStyle = "margin:0;width:25px;height:25px;display:block;";

            if (currentQcCheckFilter === "all") {
                currentQcCheckFilter = "gray";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_gray" style="' + figureStyle + '"></figure>';
            } else if (currentQcCheckFilter === "gray") {
                currentQcCheckFilter = "qq";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_y" style="' + figureStyle + '"></figure>';
            } else if (currentQcCheckFilter === "qq") {
                currentQcCheckFilter = "green";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_green" style="' + figureStyle + '"></figure>';
            } else {
                currentQcCheckFilter = "all";
                if (contentSpan) contentSpan.innerHTML = 'All';
            }
            fetchAndUpdateData(1, { qc: currentQcCheckFilter });
        }


        // Extend DataTables search for QC Check Status
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'qc-data-table') return true;

                var qcCheckFilterState = window.currentQcCheckFilter;
                if (qcCheckFilterState === "all") {
                    return true;
                }

                var rowNode = settings.aoData[dataIndex].nTr;
                var $rowNode = $(rowNode);

                if (qcCheckFilterState === "gray") {
                    // Show if it has the 'pending' attribute, which means it has a gray circle.
                    return $rowNode.attr('data-is-pending') === 'true';
                } else if (qcCheckFilterState === "qq") {
                    // Show if it has a QQ entry (yellow circle for QQ, not AOD).
                    return $rowNode.attr('data-has-qq') === 'true';
                } else if (qcCheckFilterState === "green") {
                    // Show if it has an OK entry (green circle).
                    return $rowNode.attr('data-has-ok') === 'true';
                }

                return false; // Hide by default if filter is active but no match
            }
        );

        function cancelFilters() {
            currentActivePTI = '';
            window.currentQcCheckFilter = "all";
            const qcContentSpan = document.getElementById("qcCheckColorContent");
            if (qcContentSpan) qcContentSpan.innerHTML = 'All';
            $('.qc-pti-btn').removeClass('active');
            $('.qc-status-btn').removeClass('active');
            $('.qc-status-btn[data-qc="all"]').addClass('active');
            $('#qc-search-input').val('');
            $('#qc-search-clear').hide();
            $('#qc-data-table_filter input').val('');
            fetchAndUpdateData(1, { pti: '', qc: 'all', search: '' });
        }


        // The old $(document).ready content related to DataTables initialization and specific button handlers
        // will be replaced by the new comprehensive JavaScript logic.

        $(document).ready(function() {
            // =================================================================
            // DEBUG: 確認 ready 事件是否成功觸發
            console.log("Document is ready. Attaching event handlers.");
            // =================================================================
            // General Bootstrap modal cleanup
            $(document).on('hidden.bs.modal', '.modal', function() {
                // Check if there are any other modals currently shown or in the process of showing
                // Bootstrap 3 uses 'in', Bootstrap 4/5 use 'show'
                if ($('.modal.in:visible, .modal.show:visible').length === 0) {
                    $('body').removeClass('modal-open');
                    // Remove any orphaned backdrops if no modals are visible
                    $('.modal-backdrop').remove();
                } else {
                    // If other modals are still open, ensure the body has modal-open.
                    // Bootstrap should handle this, but as a safeguard:
                    if (!$('body').hasClass('modal-open')) {
                        $('body').addClass('modal-open');
                    }
                }

                // Resume auto-update when all modals are hidden
                setTimeout(function() {
                    if (!isAnyModalOpen()) {
                        console.log('[Auto-Update] 所有 Modal 已關閉，恢復自動更新。');
                        autoUpdatePaused = false;
                        // Optional: Immediately run an update check upon resuming
                        fetchAndUpdateData();
                    }
                }, 500); // 500ms delay to ensure modal transitions are complete
            });

            // Datepicker general settings
            $.datepicker.regional["zh-TW"] = {
                closeText: "關閉",
                prevText: "&#x3C;上個月",
                nextText: "下個月&#x3E;",
                currentText: "今天",
                monthNames: ["一月", "二月", "三月", "四月", "五月", "六月", "七月", "八月", "九月", "十月", "十一月", "十二月"],
                monthNamesShort: ["一月", "二月", "三月", "四月", "五月", "六月", "七月", "八月", "九月", "十月", "十一月", "十二月"],
                dayNames: ["星期日", "星期一", "星期二", "星期三", "星期四", "星期五", "星期六"],
                dayNamesShort: ["週日", "週一", "週二", "週三", "週四", "週五", "週六"],
                dayNamesMin: ["日", "一", "二", "三", "四", "五", "六"],
                weekHeader: "週",
                dateFormat: "yy-mm-dd",
                firstDay: 1,
                isRTL: false,
                showMonthAfterYear: true,
                yearSuffix: "年"
            };
            $.datepicker.setDefaults($.datepicker.regional["zh-TW"]);

            $("#datepicker").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true
            });
            // $(".qc-modal-reply-datepicker") will be initialized after modals are added to DOM
            $("#datepicker_ate").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true
            });


            // 清除骨架動畫列，DataTable 初始化前 tbody 必須是空的
            $('#qc-data-table tbody').empty();

            // Destroy existing DataTable instance if it exists, then re-initialize
            if ($.fn.DataTable.isDataTable('#qc-data-table')) {
                $('#qc-data-table').DataTable().destroy();
            }
            dataTableInstance = $('#qc-data-table').DataTable({
                responsive: false, // Temporarily disable responsive for testing fixed column widths
                data: [], // Will be populated by populateTableWithData
                columns: [{
                        title: "bom_ing_fid",
                        visible: false,
                        data: 0
                    }, // 0
                    {
                        title: "狀態 / 檢驗",
                        width: "9%",
                        data: 1
                    }, // 1 - Status / Inspection, increased width
                    {
                        title: "客戶"
                    }, // 2 - Client
                    {
                        title: "BOM"
                    }, // 3 - BOM
                    {
                        title: "料號"
                    }, // 4 - Part Number
                    {
                        title: "回廠"
                    }, // 5 - Return Date
                    {
                        title: "製程"
                    }, // 6 - Process
                    {
                        title: "廠商"
                    }, // 7 - Manufacturer/Supplier
                    {
                        title: "總數"
                    }, // 8 - Total Quantity
                    {
                        title: "容器"
                    }, // 8 - Total Quantity
                    {
                        title: "備註"
                    }, // 9 - Remarks (bom_ing.ps)
                    {
                        title: "選項"
                    }, // 10 - Options/Actions
                    {
                        title: "Process Type ID",
                        visible: false
                    }, // 11 - Hidden PTI for filtering
                    {
                        title: "QC Check Raw",
                        visible: false,
                        className: 'never'
                    } // 12 - Hidden raw QC_check value for filtering
                ], // Make sure columnDefs are applied after initialization if needed for specific widths
                // Example of columnDefs for widths (if still needed after responsive:false)
                // "columnDefs": [
                //     { "width": "9%", "targets": 1 }, // Status
                //     { "width": "5%", "targets": 2 }, // Client
                //     { "width": "12%", "targets": 3 }, // BOM
                //     { "width": "12%", "targets": 4 }, // Part No
                // Add other column width definitions here
                // ],

                createdRow: function(row, data, dataIndex) {
                    $(row).attr('data-bom-ing-fid', data[0]); // bom_ing_fid
                    $(row).attr('data-qc-check', data[12]); // Raw QC_check value

                    // Add attributes for presence-based filtering
                    var originalItem = allRawData.find(d => d.bom_ing_fid === data[0]);
                    if (originalItem) {
                        var qcCheck = (originalItem.QC_check || '').trim();
                        var qqSqty = parseFloat(originalItem.QC_QQ_sqty) || 0;
                        var okSqty = parseFloat(originalItem.QC_ok_sqty) || 0;
                        var totalOrderQty = parseFloat(originalItem.sqty) || 0;
                        var totalCheckedQty = qqSqty + okSqty;

                        if (qqSqty > 0) $(row).attr('data-has-qq', 'true');
                        if (okSqty > 0) $(row).attr('data-has-ok', 'true');

                        // A row is pending if it's not in a final state and not fully checked, or has 0 total quantity.
                        if (qcCheck !== 'ng' && qcCheck !== 'AOD') {
                            if (totalOrderQty > 0 && totalCheckedQty < totalOrderQty || totalCheckedQty === 0) {
                                $(row).attr('data-is-pending', 'true');
                            }
                        }
                    }
                },
                // Enable DataTables buttons
                pageLength: 100,
                dom: 'Bfrtip', // This line is crucial for buttons to appear
                buttons: [
                    'copy', 'csv', 'excel', 'pdf', 'print'
                ]
            });

            // 初始載入第一頁資料（移至 populateTableWithData 暴露到 window 之後執行）

            // Initialize the QC Check filter button display to "All"
            const qcContentSpan = document.getElementById("qcCheckColorContent");
            if (qcContentSpan) {
                qcContentSpan.innerHTML = 'All'; // Set initial text to "All"
            }


            function generateModalsForItem(item) {
                let itemModalsHtml = '';
                const bomIngFidEsc = he(item.bom_ing_fid);
                const bomEsc = he(item.bom);
                const dIdEsc = he(item.d_id);
                const clientNameEsc = he(item.Client_Name);
                const processNoEsc = he(item.ProcessNo);
                const processNameEsc = he(item.ProcessName);
                const makerIdEsc = he(item.maker_id);
                const sqtyEsc = he(item.sqty);
                const itemPsEsc = he(item.ps); // bom_ing.ps (general remark)

                // Modal for QR Code
                itemModalsHtml += `
<div id="myModal_qrcode_${bomIngFidEsc}" class="modal fade" role="dialog">
    <div class="modal-dialog"> <!-- Removed modal-sm to make it default (larger) size -->
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    ${bomEsc} / ${dIdEsc}<br>
                    <small style="font-weight:normal;">總數：${sqtyEsc}</small>
                </h4>
            </div>
            <div class="modal-body" data-total-qty="${sqtyEsc}" data-bom="${bomEsc}" data-d-id="${dIdEsc}" style="min-height: 180px;"> <!-- Added data-bom, data-d-id and min-height -->
                <div class="form-group qr-modal-centered-form-group"> <!-- Container for new layout - ADDED qr-modal-centered-form-group -->
                    <div class="row qr-modal-controls-row" style="margin-bottom: 10px;"> <!-- ADDED qr-modal-controls-row -->
                        <label class="col-xs-2 control-label qr-modal-label">容器：</label>
                        <div class="col-xs-4 qr-modal-input-group">
                            <select class="form-control packaging-type" id="packaging-type-${bomIngFidEsc}">
                                <option>PP箱</option>
                                <option>蝴蝶籠</option>
                                <option>鐵桶</option>
                                <option>棧板</option>
                            </select>
                        </div>
                        <label class="col-xs-2 control-label qr-modal-label">箱數：</label>
                        <div class="col-xs-4 qr-modal-input-group">
                            <input type="number" class="form-control qty-per-unit" id="qty-per-unit-${bomIngFidEsc}" placeholder="數量" min="1"> <!-- min="1" ensures not negative, not zero -->
                        </div>
                    </div>
                </div>                
                <div class="form-group" style="margin-top: 0;">
                    <div class="row">
                        <div class="col-xs-12 calculation-result" style="padding-top: 7px; font-weight: bold;">共 ? PP箱</div> <!-- Updated initial text -->
                    </div>
                </div>
                <div class="form-group qrcode-display-area" style="text-align: center; margin-top: 15px; display: none;">
                    <!-- This area will no longer be used for preview -->
                    <div id="qrcode_image_container_${bomIngFidEsc}" style="margin-bottom: 10px;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default pull-left clear-button">清除</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                <button type="button" class="btn btn-success direct-print-qrcode-button">列印</button>
            </div>
        </div>
    </div>
</div>`;
                // QC related fields from item (ensure they exist from the corrected SQL)
                const qcPsQqEsc = he(item.QC_ps_qq);
                const qcPsNgEsc = he(item.QC_ps_ng);
                const qcPsAodRemarkEsc = he(item.QC_ps_aod_remark);
                const qcQqSqtyEsc = he(item.QC_QQ_sqty);
                const qcAodSqtyEsc = he(item.QC_aod_sqty);
                const qcNgSqtyEsc = he(item.QC_ng_sqty);
                const qcOkSqtyEsc = he(item.QC_ok_sqty);
                const qcPsOkEsc = he(item.QC_ps_ok);

                // Modal for "報工" (Reply) - No changes to this modal structure
                // itemModalsHtml += `
                // <div id="myModal_reply_${bomIngFidEsc}" class="modal fade" role="dialog">
                //     <div class="modal-dialog">
                //         <div class="modal-content">
                //             <div class="modal-header">
                //                 <button type="button" class="close" data-dismiss="modal">&times;</button>
                //                 <h4 class="modal-title">${bomEsc} / ${dIdEsc} 允收紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}&emsp;檢驗日</small></h4>
                //             </div>
                //             <div class="modal-body">
                //                 <form action="../../src/store/_updateQC_check_list_reply.php?bi=${bomIngFidEsc}&id=${currentUserId}" method="POST" class="form-horizontal form-label-center" novalidate>
                //                     <input name="bom_ing_id" value="${bomIngFidEsc}" type="hidden">
                //                     <table class="table table-striped">
                //                         <tr><td>報工日期 <span class="required">*</span></td><td><input type="text" id="datepicker_QCreply_${bomIngFidEsc}" class="qc-modal-reply-datepicker" required size="8" name="datepicker_QCreply" placeholder="日期"><small>(預設為今日)</small></td></tr>
                //                         <tr><td>客戶 <span class="required">*</span></td><td><input value="${clientNameEsc}" type="text" readonly style="border-style:none"></td></tr>
                //                         <tr><td>料號 <span class="required">*</span></td><td><input value="${dIdEsc}" type="text" readonly style="border-style:none"></td></tr>
                //                         <tr><td>製程 <span class="required">*</span></td><td><input value="[${processNoEsc}] ${processNameEsc} - ${makerIdEsc}" type="text" readonly style="border-style:none"></td></tr>
                //                         <tr><td>發單數 <span class="required">*</span></td><td><input name="sqty" value="${sqtyEsc}" required type="text" size="5"></td></tr>
                //                         <tr><td>本次加工數 <span class="required">*</span></td><td><input name="oready_sqty" required type="text" size="5"> <small>(空白=發單總數)</small></td></tr>
                //                         <tr><td>備註 <span class="required">*</span></td><td><input name="ps" size="30" required type="text"></td></tr>
                //                     </table>
                //                     <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div>
                //                 </form>
                //             </div>
                //         </div>
                //     </div>
                // </div>`;

                // Modal for "異常" (QQ) - No changes to this modal structure
                itemModalsHtml += `
                <div id="myModal_qq_${bomIngFidEsc}" class="modal fade" role="dialog">
                    <div class="modal-dialog"><div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">${bomEsc} / ${dIdEsc} 異常紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="../../src/store/_updateQC_check_list_QQ.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}" data-initial-abnormal-qty="${he(item.QC_QQ_sqty || 0)}">
                                <div class="abnormal-entries-container">                                   
                                    <div class="abnormal-entry-row abnormal-entry-header" style="display: flex; margin-bottom: 5px; font-weight: bold;">
                                        <div style="flex: 0 0 120px; margin-right: 10px;">異常數</div>
                                        <div style="flex: 1; margin-right: 10px;">QC內部簡易單據(說明)</div>
                                        <div style="flex: 0 0 70px; margin-right: 10px; text-align:center;">日期</div>
                                        <div style="flex: 0 0 110px;">操作</div>
                                    </div>
                                    <div class="ln_solid" style="margin-top: 0; margin-bottom: 10px;"></div>
                                    <div id="abnormal-rows-wrapper_${bomIngFidEsc}">
                                        <!-- Initial row will be cleared and populated by JavaScript -->
                                        <div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                                <input type="number" class="form-control abnormal-qty-input" name="qq_total_qty[]" value="" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                <input type="hidden" name="qc_check_id[]" value="">
                                            </div>
                                            <div style="flex: 1; margin-right: 10px;">
                                                <textarea rows="1" class="form-control abnormal-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="請填寫異常原因"></textarea>
                                            </div>
                                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px; font-size:0.9em;" class="abnormal-check-date"></div> <!-- Date display -->
                                            <div style="flex: 0 0 80px; text-align: left;" class="abnormal-action-buttons">
                                                <button type="button" class="btn btn-warning btn-xs add-abnormal-row"><i class="fa fa-plus"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Hidden section for QC Supervisor Decision and Abnormal Order No -->
                                <div style="display: none;">
                                    <div class="ln_solid"></div>
                                    <div class="form-group text-left" style="margin-top: 10px; margin-bottom: 15px;">
                                        <span style="font-weight: bold; margin-right: 10px;">品管主管判定:</span>
                                        <button type="button" class="btn btn-success btn-xs" data-toggle="tooltip" title="特採">特採</button>
                                        <button type="button" class="btn btn-warning btn-xs" data-toggle="tooltip" title="退回原加工商">驗退</button>
                                        <button type="button" class="btn btn-secondary btn-xs" data-toggle="tooltip" title="由超正加入其他工序重工">重工</button>
                                        <button type="button" class="btn btn-danger btn-xs" data-toggle="tooltip" title="報廢不補(不重製)">報廢</button>
                                        <button type="button" class="btn btn-info btn-xs" data-toggle="tooltip" title="報廢並重製">重製</button>
                                    </div>
                                </div>
                                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="display: none;"><input type="text" name="abnormal_order_no" class="form-control" placeholder="異常單號 (選填)" style="width: 150px; display: inline-block;"></div>
                                    <div style="display: flex; align-items: center; margin-left: auto;"><!-- Added margin-left: auto to push buttons to the right -->
                                        <button type="button" class="btn btn-secondary clear-all-qq-entries-btn" style="margin-right: 8px;">清除並儲存</button><button type="button" class="btn btn-default" data-dismiss="modal" style="margin-right: 8px;">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></div>
                            </form>
                        </div>
                    </div></div>
                </div>`;

                // Modal for "特採" (AOD) - No changes to this modal structure
                itemModalsHtml += `<div id="myModal_aod_${bomIngFidEsc}" class="modal fade" role="dialog"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">${bomEsc} / ${dIdEsc} 特採紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4></div><div class="modal-body"><form method="POST" action="../../src/store/_updateQC_check_list_AOD.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}" data-initial-abnormal-qty="${he(item.QC_QQ_sqty || 0)}"><div class="form-group" style="margin-bottom:10px;"><div style="display:flex;align-items:center;"><label class="control-label" style="margin-right:5px;white-space:nowrap;margin-bottom:0;flex-shrink:0;">特採數量：</label><input type="number" class="form-control" name="aod_total_qty" value="${qcAodSqtyEsc}" style="width:100px;" min="0" max="99999" title="特採數量" data-toggle="tooltip"></div></div><div class="form-group"><textarea rows="5" class="form-control" name="QCmessage">${qcPsAodRemarkEsc}</textarea></div><div class="modal-footer"><button type="button" class="btn btn-warning clear-textarea-btn">清除並儲存</button>&nbsp;<button type="button" class="btn btn-default" data-dismiss="modal">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></form></div></div></div></div>`;
                // Modal for "驗退" (NG) - No changes to this modal structure
                itemModalsHtml += `<div id="myModal_ng_${bomIngFidEsc}" class="modal fade" role="dialog"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">${bomEsc} / ${dIdEsc} 驗退紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4></div><div class="modal-body"><form method="POST" action="../../src/store/_updateQC_check_list_ng.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}" data-initial-abnormal-qty="${he(item.QC_QQ_sqty || 0)}"><div class="form-group" style="margin-bottom:10px;"><div style="display:flex;align-items:center;"><label class="control-label" style="margin-right:5px;white-space:nowrap;margin-bottom:0;flex-shrink:0;">驗退數量：</label><input type="number" class="form-control" name="ng_total_qty" value="${qcNgSqtyEsc}" style="width:100px;" min="0" max="99999" title="驗退數量" data-toggle="tooltip"></div></div><div class="form-group"><textarea rows="5" class="form-control" name="QCmessage">${qcPsNgEsc}</textarea></div><div class="modal-footer"><button type="button" class="btn btn-warning clear-textarea-btn">清除並儲存</button>&nbsp;<button type="button" class="btn btn-default" data-dismiss="modal">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></form></div></div></div></div>`;

                // Modal for "允收" (OK) - Modified to be multi-row like "異常"
                itemModalsHtml += `
                <div id="myModal_ok_${bomIngFidEsc}" class="modal fade" role="dialog">
                    <div class="modal-dialog"><div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">${bomEsc} / ${dIdEsc} 允收紀錄<br><small>製程：${processNameEsc}&emsp;廠商：${makerIdEsc}&emsp;總數：${sqtyEsc}<br>備註：${itemPsEsc}</small></h4>
                        </div>
                        <div class="modal-body">
                            <form method="POST" action="../../src/store/_updateQC_check_list_ok.php?bi=${bomIngFidEsc}&id=${currentUserId}" data-sqty="${sqtyEsc}">
                                <div class="ok-entries-container">
                                    <div class="ok-entry-row ok-entry-header" style="display: flex; margin-bottom: 5px; font-weight: bold;">
                                        <div style="flex: 0 0 120px; margin-right: 10px;">允收數量</div>
                                        <div style="flex: 1; margin-right: 10px;">允收備註 (選填)</div>
                                        <div style="flex: 0 0 80px; margin-right: 10px;">日期</div>
                                        <div style="flex: 0 0 80px;">&nbsp;</div>
                                    </div>
                                    <div class="ln_solid" style="margin-top: 0; margin-bottom: 10px;"></div>
                                    <div id="ok-rows-wrapper_${bomIngFidEsc}">
                                        <!-- Rows will be dynamically inserted here by JavaScript -->
                                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px; text-align:center;">
                                            <div style="flex: 1;">載入中...</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                                    <!-- Left side: New container fields -->
                                    <div>
                                        <div class="form-group" style="display: flex; align-items: center; gap: 5px; margin-bottom: 5px;">
                                            <label style="white-space: nowrap; margin-bottom: 0;">容器:</label>
                                            <select class="form-control" name="container[]" style="width: 100px; height: 30px; padding: 2px 6px;">
                                                <option value="">請選擇</option>
                                                <option value="P">PP箱</option>
                                                <option value="E">蝴蝶籠</option>
                                                <option value="T">鐵桶</option>
                                                <option value="板">棧板</option>
                                            </select>
                                            <label style="white-space: nowrap; margin-left: 10px; margin-bottom: 0;">箱數:</label>
                                            <input type="number" name="quantity[]" class="form-control" min="0" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')" style="width: 70px; height: 30px; padding: 2px 6px;"></div>
                                        <div class="form-group" style="display: flex; align-items: center; gap: 5px; margin-bottom: 0;">
                                            <label style="white-space: nowrap; margin-bottom: 0;">容器:</label>
                                            <select class="form-control" name="container[]" style="width: 100px; height: 30px; padding: 2px 6px;">
                                                <option value="">請選擇</option>
                                                <option value="P">PP箱</option>
                                                <option value="E">蝴蝶籠</option>
                                                <option value="T">鐵桶</option>
                                                <option value="板">棧板</option>
                                            </select>
                                            <label style="white-space: nowrap; margin-left: 10px; margin-bottom: 0;">箱數:</label>
                                            <input type="number" name="quantity[]" class="form-control" min="0" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')" style="width: 70px; height: 30px; padding: 2px 6px;"></div>
                                    </div>
                                    <span>　</span>
                                    <!-- Right side: Existing buttons -->
                                    <div style="display: flex; align-items: center;">
                                        <button type="button" class="btn btn-secondary clear-and-save-ok-btn" style="margin-right: 8px;">清除並儲存</button><button type="button" class="btn btn-default" data-dismiss="modal" style="margin-right: 8px;">關閉<small>(不儲存)</small></button><input type="submit" class="btn btn-primary" value="儲存"></div></div>
                                </div>
                            </form>
                        </div>
                    </div></div>
                </div>`;

                // ⭐ 新增：Modal for "完成" (Complete)
                itemModalsHtml += `
                <div id="myModal_complete_${bomIngFidEsc}" class="modal fade" role="dialog" data-bom-ing-fid="${bomIngFidEsc}">
                    <div class="modal-dialog modal-sm">
                        <div class="modal-content">
                            <div class="modal-header">
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                <h4 class="modal-title">${bomEsc} / ${dIdEsc}</h4>
                            </div>
                            <div class="modal-body" style="font-size: 1.2em; line-height: 1.6;">
                                <!-- Content will be dynamically generated by JavaScript -->
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary btn-confirm-completion">確認完成</button>
                                <button type="button" class="btn btn-default" data-dismiss="modal" style="margin-left: 10px;">關閉</button>
                            </div>
                        </div>
                    </div>
                </div>`;
                return itemModalsHtml;
            }

            /**
             * Updated populateTableWithData to include "容器" column
             */

            function populateTableWithData(rawData) {
                if (!dataTableInstance) {
                    console.error("DataTable instance is not available for populating data.");
                    return;
                }
                if (!rawData || rawData.length === 0) {
                    console.warn("No data provided to populate the table.");
                    dataTableInstance.clear().draw();
                    return;
                }

                var tableData = [];
                var modalsHtmlBuffer = '';

                rawData.forEach(function(item) {
                    // Ensure defaults
                    item.QC_check = item.QC_check || '';
                    item.Client_Name = item.Client_Name || '';
                    item.bom = item.bom || '';
                    item.d_id = item.d_id || '';
                    item.QC_QQ_sqty = item.QC_QQ_sqty || 0;
                    item.QC_ok_sqty = item.QC_ok_sqty || 0;
                    item.latest_QQ_date_formatted = item.latest_QQ_date_formatted || '';
                    item.latest_ok_date_formatted = item.latest_ok_date_formatted || '';
                    item.return_date = item.return_date || '';
                    item.ProcessNo = item.ProcessNo || '';
                    item.ProcessName = item.ProcessName || '';
                    item.maker_id = item.maker_id || '';
                    item.sqty = item.sqty || 0;
                    item.ps = item.ps || '';
                    item.bom_ing_fid = item.bom_ing_fid || '';
                    item.process_type_id = item.process_type_id || '';

                    // Status HTML (same as original logic)
                    var statusHtml = '';
                    var qcCheck = item.QC_check.trim();
                    var qcCheckDateForOverride = he(item.QC_check_date || '');
                    var totalOrderQty = parseFloat(item.sqty) || 0;
                    var qqSqty = parseFloat(item.QC_QQ_sqty) || 0;
                    var okSqty = parseFloat(item.QC_ok_sqty) || 0;
                    var totalCheckedQty = qqSqty + okSqty;
                    var latestQqDate = he(item.latest_QQ_date_formatted);
                    var latestOkDate = he(item.latest_ok_date_formatted);
                    var statusParts = [];

                    if (qcCheck === 'ng') {
                        statusHtml = `<div class="qc-flex"><span class="circle_red"></span><small>${qcCheckDateForOverride}</small></div>`;
                    } else if (qcCheck === 'AOD') {
                        statusHtml = `<div class="qc-flex"><span class="circle_y"></span><small>${qcCheckDateForOverride}</small></div>`;
                    } else {
                        if (totalOrderQty > 0 && totalCheckedQty >= totalOrderQty) {
                            if (qqSqty > 0 && okSqty > 0) {
                                statusParts.push(`<span class="circle_y"></span><small>${he(String(qqSqty))}</small>`);
                                statusParts.push(`<span class="circle_green"></span><small>${he(String(okSqty))}</small>`);
                            } else if (qqSqty > 0) {
                                statusParts.push(`<span class="circle_y"></span><small>${latestQqDate}</small>`);
                            } else if (okSqty > 0) {
                                statusParts.push(`<span class="circle_green"></span><small>${latestOkDate}</small>`);
                            } else {
                                statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                            }
                        } else {
                            if (qqSqty > 0) statusParts.push(`<span class="circle_y"></span><small>${latestQqDate}</small>`);
                            if (okSqty > 0) statusParts.push(`<span class="circle_green"></span><small>${latestOkDate}</small>`);
                            if (statusParts.length === 0) statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                        }
                        statusHtml = `<div class="qc-flex">${statusParts.join('&emsp;')}</div>`;
                    }

                    // QR code and BOM/button functionality
                    var qrCodeButtonHtml = `
            <button type="button" class="btn btn-xs btn-default qr-code-btn-tooltip" style="margin-right: 3px; padding: 1px 5px; display: inline-flex; align-items: center; justify-content: center;" onclick="openQCModal('qrcode','${he(item.bom_ing_fid)}')" title="顯示QR Code">
                <i class="fa fa-qrcode" style="font-size: 1.2em;"></i>
            </button>`;
                    var bomHtml = `
            <button type="button" class="btn-copy" onclick="egSystemCopyTextAndToggleIcon('${he(item.bom)}', this)" title="複製BOM" style="margin-right: 3px;">
                <i class="fa fa-copy"></i>
            </button> ${he(item.bom)}`;
                    var dIdHtml = `
            ${qrCodeButtonHtml}
            <button type="button" class="btn-copy" onclick="egSystemCopyTextAndToggleIcon('${he(item.d_id)}', this)" title="複製料號" style="margin-right: 3px;">
                <i class="fa fa-copy"></i>
            </button>
            <a href="/nas/${he(item.bom)}.jpg" target="_blank">${he(item.d_id)}</a>`;

                    var returnDateHtml = he(item.return_date);
                    var processHtml = item.ProcessNo ? `[${he(item.ProcessNo)}] ${he(item.ProcessName)}` : '[未設定 BOM]';
                    var makerIdHtml = he(item.maker_id);
                    var sqtyHtml = he(item.sqty);

                    var containerHtml = '<div class="container-cell">';
                    if (item.BIQC_ps && item.BIQC_ps.trim()) {
                        containerHtml += `<button type="button" class="container-btn">${he(item.BIQC_ps)}</button>`;
                    }
                    if (item.BIQC_ps2 && item.BIQC_ps2.trim()) {
                        containerHtml += `<button type="button" class="container-btn">${he(item.BIQC_ps2)}</button>`;
                    }
                    containerHtml += '</div>';


                    // 「備註」欄由 generatePsHtml 處理
                    var psHtml = generatePsHtml(item); // This handles QC remarks

                    // 1. 顯示來自 bom 主表的備註 (bom.bom_ps)
                    var bomMainPs = item.bom_bom_ps || ''; // bom_bom_ps is the alias for bom.bom_ps
                    if (bomMainPs.trim() !== '') {
                        // 將其加在所有內容的最前面
                        psHtml = `<div style="padding: 2px 5px; margin-bottom: 2px; background-color: #f0f0f0; border-radius: 3px;">${he(bomMainPs)}</div>` + psHtml;
                    }

                    // 2. 顯示來自 bom_ing 的生管備註 (bom_ing.single_bet_ps)
                    var singleBetPs = item.single_bet_ps || '';
                    if (singleBetPs.trim() !== '') {
                        psHtml += `<div style="background-color: #fcf8e3; color: #8a6d3b; padding: 2px 5px; margin-top: 3px; border-radius: 3px;">生管： ${he(singleBetPs)}</div>`;
                    }

                    // 操作按鈕（異常單改從 QQ modal 內開立）
                    var optionsHtml = `<div class="qc-action-group">
            <button type="button" class="btn btn-warning btn-xs" onclick="openQCModal('qq','${he(item.bom_ing_fid)}')" title="記錄異常">異常</button>
            <button type="button" class="btn btn-success btn-xs" onclick="openQCModal('ok','${he(item.bom_ing_fid)}')" title="允收">允收</button>
            <button type="button" class="btn btn-info btn-xs"    onclick="openQCModal('complete','${he(item.bom_ing_fid)}')" title="完成檢驗">完成</button>
            </div>`;

                    // Push data
                    tableData.push([
                        item.bom_ing_fid,
                        statusHtml,
                        he(item.Client_Name),
                        bomHtml,
                        dIdHtml,
                        returnDateHtml,
                        processHtml,
                        makerIdHtml,
                        sqtyHtml,
                        containerHtml,
                        psHtml,
                        optionsHtml,
                        item.process_type_id,
                        (item.QC_check || '').trim()
                    ]);

                    modalsHtmlBuffer += ''; // Modals now built lazily on demand
                });

                dataTableInstance.clear().rows.add(tableData).draw(false);
                // 不預建所有 modal，改成按需產生（lazy）
                // $('#modals-container').html(modalsHtmlBuffer);

                // Reinitialize tooltips and datepickers
                $('body').tooltip({
                    selector: '[data-toggle="tooltip"]'
                });
                $(".qc-modal-reply-datepicker").datepicker({
                    changeMonth: true,
                    changeYear: true,
                    showMonthAfterYear: true
                });
            }

            // 暴露到全域，讓 ready 外的 fetchAndUpdateData 可以呼叫
            window.populateTableWithData    = populateTableWithData;
            window.generateModalsForItem    = generateModalsForItem;

            // ── Lazy Modal 開啟函式 ──────────────────────────────────
            // 點擊時才建立 modal DOM，避免預建幾十個 modal 卡頓
            window.openQCModal = function(type, bomIngFid) {
                var modalId = '#myModal_' + type + '_' + bomIngFid;

                // 若 modal 已存在直接開
                if ($(modalId).length) {
                    $(modalId).modal('show');
                    return;
                }

                // 從 allRawData 找到此筆資料
                var item = (window.allRawData || []).find(function(d) {
                    return String(d.bom_ing_fid) === String(bomIngFid);
                });
                if (!item) {
                    alert('找不到資料，請重新整理頁面');
                    return;
                }

                // 建立這筆的 modals 並 append
                var html = generateModalsForItem(item);
                $('#modals-container').append(html);

                // 初始化 datepicker（modal 內若有）
                $(".qc-modal-reply-datepicker").datepicker({
                    changeMonth: true, changeYear: true, showMonthAfterYear: true
                });
                $('body').tooltip({ selector: '[data-toggle="tooltip"]' });

                $(modalId).modal('show');
            };

            // 初始載入第一頁（必須在 populateTableWithData 暴露後才呼叫）
            fetchAndUpdateData(1);

            // --- START: New Double-click Functionality ---
            var $globalSearchInput = $('#qc-data-table_filter input');

            // Double-click on table cells (料號 or 廠商) to search
            $('#qc-data-table tbody').on('dblclick', 'td', function() {
                if (!dataTableInstance || !allRawData) return; // Ensure DataTable instance and raw data exist

                var cell = dataTableInstance.cell(this);
                // Get the index of the column in the original 'columns' array configuration
                var columnIndexInConfig = cell.index().column;

                var row = dataTableInstance.row($(this).closest('tr'));
                var rowDataArray = row.data(); // This is the array [item.bom_ing_fid, statusHtml, ...]

                if (!rowDataArray) return;

                var bomIngFid = rowDataArray[0]; // bom_ing_fid is at index 0 of the rowDataArray
                var originalItem = allRawData.find(function(item) {
                    return item.bom_ing_fid === bomIngFid;
                });

                if (!originalItem) return;

                var searchText = '';

                if (columnIndexInConfig === 4) { // 料號 column (index 4 in 'columns' array config)
                    searchText = originalItem.d_id;
                } else if (columnIndexInConfig === 7) { // 廠商 column (index 7 in 'columns' array config)
                    searchText = originalItem.maker_id;
                }

                if (searchText && $globalSearchInput.length) {
                    dataTableInstance.search(searchText).draw();
                }
            });

            // Double-click on global search input to clear
            if ($globalSearchInput.length) {
                $globalSearchInput.on('dblclick', function() {
                    if ($(this).val() !== '') {
                        $(this).val(''); // 清除輸入框的顯示內容
                        dataTableInstance.search('').draw();
                    }
                });
            }
            // 全域搜尋欄連動後端篩選（debounce 400ms）
            var qcSearchTimer = null;
            $(document).on('keyup', '#qc-search-input', function() {
                var val = $(this).val();
                $('#qc-search-clear').toggle(val.length > 0);
                clearTimeout(qcSearchTimer);
                qcSearchTimer = setTimeout(function() {
                    fetchAndUpdateData(1, { search: val });
                }, 400);
            });
            // 清除搜尋
            $(document).on('click', '#qc-search-clear', function() {
                $('#qc-search-input').val('').focus();
                $(this).hide();
                fetchAndUpdateData(1, { search: '' });
            });
            // --- END: New Double-click Functionality ---

            // --- AJAX for direct action buttons (允收, 待驗) ---
            // Use event delegation for dynamically added buttons within the table
            $('#qc-data-table tbody').on('click', '.qc-action-btn', function() {
                var button = $(this);
                var action = button.data('action');
                var bomIngId = button.data('bi');
                var userId = button.data('id');

                var url;
                var originalButtonText = button.text(); // Store original text

                switch (action) {
                    case 'wait':
                        url = '../../src/store/_updateQC_check_list_wait.php';
                        break;
                }

                button.prop('disabled', true).text('處理中...');

                $.ajax({
                    url: url,
                    type: 'GET', // These scripts expect GET parameters
                    data: {
                        bi: bomIngId,
                        id: userId
                    },
                    dataType: 'json',
                    success: function(response) {
                        var targetRow = button.closest('tr');
                        var statusCell = targetRow.find('td:nth-child(1)'); // Corrected: Status is the 1st visible cell

                        if (response.success) {
                            statusCell.empty(); // Clear previous status
                            var newStatusHtml = '';
                            var newQcCheckValue = '';

                            if (action === 'wait') { // Only 'wait' action remains here
                                newQcCheckValue = ''; // Filter expects "" for gray/待驗
                                newStatusHtml = '<div class="qc-flex">' +
                                    '<span class="circle_gray"></span>' +
                                    '<small>待驗</small>' +
                                    '</div>';
                            }
                            statusCell.html(newStatusHtml);
                            targetRow.attr('data-qc-check', newQcCheckValue); // Update the data-qc-check attribute on the TR

                            // Update QC備註 button based on response
                            updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);

                            if (dataTableInstance) {
                                var dtRow = dataTableInstance.row(targetRow);
                                dtRow.invalidate('dom'); // Invalidate based on current DOM
                                // If responsive is active and might have changed the row structure
                                if (typeof dataTableInstance.responsive === 'object' && dataTableInstance.responsive.hasHidden && dataTableInstance.responsive.hasHidden()) {
                                    dataTableInstance.responsive.recalc();
                                }
                                dataTableInstance.draw(false); // Redraw the table
                            }
                            showTemporaryMessage('更新成功', 'success');
                        } else {
                            showTemporaryMessage('更新失敗: ' + response.message, 'error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                    },
                    complete: function() {
                        // Always re-enable the button and restore its original text after the AJAX call is complete.
                        button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });

            // Helper function to get relevant quantities for validation
            function getRelevantQuantities(formElement) {
                const $form = $(formElement);
                const actionUrl = $form.attr('action');
                const bomIngId = new URL(actionUrl, window.location.href).searchParams.get("bi");
                const sqty = parseFloat($form.data('sqty') || 0);

                let currentAbnormalQty = 0;
                const abnormalQtyInput = $('#abnormal_total_qty_' + bomIngId);
                if (abnormalQtyInput.length && abnormalQtyInput.val().trim() !== '') {
                    currentAbnormalQty = parseFloat(abnormalQtyInput.val()) || 0;
                } else {
                    currentAbnormalQty = parseFloat($form.data('initial-abnormal-qty') || 0);
                }

                return {
                    bomIngId,
                    sqty,
                    currentAbnormalQty
                };
            }

            // 放在 custom.js 開頭、jQuery ready 之外也行，只要先載入即可
            function qqCallback(res, $form) {
                // res.individual_qc_entries / res.data…
                updateTableRowDOM(res.individual_qc_entries);
                $form.closest('.modal').modal('hide');
            }

            function okCallback(res, $form) {
                // 同樣地
                updateTableRowDOM(res.individual_qc_entries);
                $form.closest('.modal').modal('hide');
            }


            // --- AJAX for modal form submissions (異常, 驗退) ---
            function handleModalFormSubmit(form, successCallback) {
                console.log("handleModalFormSubmit is being attached to a form."); // DEBUG
                form.on('submit', function(e) {
                    // --- START VALIDATION ---
                    const $currentForm = $(this);
                    const actionUrl = $currentForm.attr('action');
                    const quantities = getRelevantQuantities(this); // 'this' is the form element

                    let isValid = true; // Assume valid initially
                    let validationMessage = "";

                    // --- START VALIDATION ---
                    if (actionUrl.includes('_updateQC_check_list_QQ.php')) { // 異常 Modal
                        let totalAbnormalQtySum = 0;
                        let hasIncompleteQQEntry = false;
                        $currentForm.find('.abnormal-entry-row:not(.abnormal-entry-header)').each(function() {
                            const qtyInput = $(this).find('input[name="qq_total_qty[]"]');
                            const remarkInput = $(this).find('textarea[name="QCmessage[]"]');
                            const val = $(this).val();
                            if (qtyInput.val().trim() !== '' || remarkInput.val().trim() !== '' || qtyInput.val() === '0') { // If either field has data or quantity is explicitly 0
                                if (qtyInput.val().trim() === '' || parseFloat(qtyInput.val()) <= 0 || remarkInput.val().trim() === '') {
                                    hasIncompleteQQEntry = true;
                                }
                                totalAbnormalQtySum += parseFloat(qtyInput.val()) || 0;
                            }
                        });
                        if (hasIncompleteQQEntry) isValid = false;
                        validationMessage = "異常記錄的數量和原因皆需填寫，且數量需為正數。";
                        if (isValid && quantities.sqty > 0 && totalAbnormalQtySum > quantities.sqty) { // Only check if total sqty is positive
                            isValid = false;
                            validationMessage = "異常總數 (" + totalAbnormalQtySum + ") 已超過發單總數 (" + quantities.sqty + ")，請確認。";
                        }
                    } else if (actionUrl.includes('_updateQC_check_list_AOD.php')) { // 特採 Modal
                        const aodQtyEntered = parseFloat($currentForm.find('input[name="aod_total_qty"]').val()) || 0;
                        if (quantities.currentAbnormalQty <= 0 && aodQtyEntered > 0) {
                            isValid = false;
                            validationMessage = "請先輸入有效的異常總數，才能輸入特採數量。";
                        } else if (aodQtyEntered > 0 && aodQtyEntered > quantities.currentAbnormalQty) {
                            isValid = false;
                            validationMessage = "特採數量 (" + aodQtyEntered + ") 不可大於異常總數 (" + quantities.currentAbnormalQty + ")，請確認。";
                        }
                    } else if (actionUrl.includes('_updateQC_check_list_ng.php')) { // 驗退 Modal
                        const ngQtyEntered = parseFloat($currentForm.find('input[name="ng_total_qty"]').val()) || 0;
                        if (quantities.currentAbnormalQty <= 0 && ngQtyEntered > 0) {
                            isValid = false;
                            validationMessage = "請先輸入有效的異常總數，才能輸入驗退數量。";
                        } else if (ngQtyEntered > 0 && ngQtyEntered > quantities.currentAbnormalQty) {
                            isValid = false;
                            validationMessage = "驗退數量 (" + ngQtyEntered + ") 不可大於異常總數 (" + quantities.currentAbnormalQty + ")，請確認。";
                        }
                    } else if (actionUrl.includes('_updateQC_check_list_ok.php')) { // 允收 Modal
                        let totalOkQtySum = 0;
                        let hasNegativeOkQty = false;
                        $currentForm.find('input[name="ok_total_qty[]"]').each(function() {
                            const val = $(this).val();
                            if (val.trim() !== '') {
                                const qty = parseFloat(val) || 0;
                                if (qty < 0) hasNegativeOkQty = true;
                                totalOkQtySum += qty;
                            }
                        });
                        if (hasNegativeOkQty) {
                            isValid = false;
                            validationMessage = "允收數量不可為負數。";
                        } else if (isValid && quantities.sqty > 0 && totalOkQtySum > quantities.sqty) { // Only check if total sqty is positive
                            // The confirmation for exceeding total quantity can be handled here or removed if not desired for "OK"
                            if (!confirm("允收總數 (" + totalOkQtySum + ") 已超過發單總數 (" + quantities.sqty + ")。\n您確定要儲存嗎？")) {
                                isValid = false;
                            }
                        }
                    }

                    // --- New Quantity Check Logic (Integrated with existing validation) ---
                    const bomIngId = new URL(actionUrl, window.location.href).searchParams.get("bi");
                    console.log("[QC Check] Extracted bomIngId:", bomIngId); // DEBUG
                    const itemData = window.allRawData.find(item => item.bom_ing_fid === bomIngId);
                    console.log("[QC Check] Found itemData:", JSON.parse(JSON.stringify(itemData || null))); // DEBUG (stringify for deep copy log)

                    if (itemData && isValid) { // Only proceed if itemData is found and current validation is still valid
                        console.log("[QC Check] itemData is valid, proceeding with new quantity check."); // DEBUG
                        const totalOrderQty = parseFloat(itemData.sqty) || 0;
                        console.log("[QC Check] totalOrderQty (from itemData.sqty:", itemData.sqty, "):", totalOrderQty); // DEBUG

                        let sumOfQuantitiesInModal = 0;
                        let existingRelatedQuantity = 0;
                        let quantityType = ""; // For message

                        if (actionUrl.includes('_updateQC_check_list_QQ.php')) { // QQ Modal
                            quantityType = "異常";
                            existingRelatedQuantity = parseFloat(itemData.QC_ok_sqty) || 0; // Existing OK quantity
                            console.log("[QC Check] QQ Modal - existingRelatedQuantity (itemData.QC_ok_sqty:", itemData.QC_ok_sqty, "):", existingRelatedQuantity); // DEBUG
                            $currentForm.find('input[name="qq_total_qty[]"]').each(function() {
                                sumOfQuantitiesInModal += parseFloat($(this).val()) || 0;
                            });
                        } else if (actionUrl.includes('_updateQC_check_list_ok.php')) { // OK Modal
                            quantityType = "允收";
                            existingRelatedQuantity = parseFloat(itemData.QC_QQ_sqty) || 0; // Existing QQ quantity
                            console.log("[QC Check] OK Modal - existingRelatedQuantity (itemData.QC_QQ_sqty:", itemData.QC_QQ_sqty, "):", existingRelatedQuantity); // DEBUG
                            $currentForm.find('input[name="ok_total_qty[]"]').each(function() {
                                sumOfQuantitiesInModal += parseFloat($(this).val()) || 0;
                            });
                        }
                        console.log("[QC Check] sumOfQuantitiesInModal:", sumOfQuantitiesInModal); // DEBUG

                        const totalSum = sumOfQuantitiesInModal + existingRelatedQuantity;
                        console.log("[QC Check] totalSum (sumInModal + existingRelated):", totalSum); // DEBUG

                        if (totalOrderQty > 0 && totalSum > totalOrderQty) {
                            console.log("[QC Check] Condition MET: totalOrderQty > 0 && totalSum > totalOrderQty. Showing confirm."); // DEBUG
                            const confirmationMessage = `輸入${quantityType}數量總和 (${totalSum}) 已超過發單總數 (${totalOrderQty})。\n是否仍要儲存？`;
                            if (!confirm(confirmationMessage)) {
                                console.log("[QC Check] User cancelled confirm."); // DEBUG
                                isValid = false;
                            } else {
                                console.log("[QC Check] User confirmed save."); // DEBUG
                            }
                        } else {
                            console.log("[QC Check] Condition NOT MET for confirm. totalOrderQty:", totalOrderQty, "totalSum:", totalSum, "totalOrderQty > 0:", (totalOrderQty > 0), "totalSum > totalOrderQty:", (totalSum > totalOrderQty)); // DEBUG
                        }
                    } else {
                        console.log("[QC Check] Skipped new quantity check. itemData found:", !!itemData, "isValid:", isValid); // DEBUG
                    }
                    if (!isValid) {
                        e.preventDefault();
                        if (validationMessage) {
                            alert(validationMessage);
                        }
                        return;
                    }


                    // If validation passes, prevent default for AJAX and proceed
                    e.preventDefault();
                    var submitButton = form.find('input[type="submit"]');
                    var originalButtonText = submitButton.val();

                    submitButton.prop('disabled', true).val('儲存中...');

                    $.ajax({
                        // ⭐ 關鍵點 1: form.attr('action') 決定了要將資料送到哪個 PHP 檔案。
                        url: form.attr('action'),
                        type: 'POST',
                        // ⭐ 關鍵點 2: form.serialize() 將表單內所有欄位打包成字串，
                        // 例如 "quantity[]=10&container[]=P&QCmessage[]=some_text"
                        // 並透過 HTTP POST 請求發送到後端。
                        data: form.serialize(), // 這行會收集 container[] 和 quantity[] 的資料
                        dataType: 'json',
                        success: function(response) {
                            // --- GEMINI CODE ASSIST: STEP 1 ---
                            var actionUrl = form.attr('action');
                            var fullUrl = new URL(actionUrl, window.location.href); // Provide base URL
                            var bomIngId = fullUrl.searchParams.get("bi");

                            // --- GEMINI CODE ASSIST: STEP 2 ---
                            // Find the table row using the bom_ing_fid
                            var $targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                            console.log("[AJAX Success] Target row for bom_ing_fid", bomIngId, ":", $targetRow);
                            // --- END GEMINI CODE ASSIST: STEP 2 ---

                            console.log("[AJAX Success] Modal submitted for bom_ing_fid:", bomIngId, "Response:", response);

                            if (response.success) {
                                // --- GEMINI CODE ASSIST: STEP 3 ---
                                if (bomIngId && $targetRow.length > 0) {
                                    console.log("[AJAX Success] Proceeding to fetch latest data for row:", bomIngId);
                                    $.ajax({
                                        url: '../../src/store/_fetch_qc_row_details.php', // New endpoint
                                        type: 'GET',
                                        data: {
                                            bi: bomIngId
                                        },
                                        dataType: 'json',
                                        success: function(latestDataResponse) {
                                            console.log("[Fetch Latest Data Success] bom_ing_fid:", bomIngId, "Latest Data:", latestDataResponse);
                                            if (latestDataResponse.success && latestDataResponse.data) {
                                                // --- GEMINI CODE ASSIST: STEP 4 & 5 Integration (Corrected) ---
                                                // Step 4: Update the DOM for the target row (uses corrected indices now)
                                                updateTableRowDOM($targetRow, latestDataResponse.data);

                                                // Step 5: Update allRawData for consistency
                                                if (window.allRawData && bomIngId) {
                                                    const itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                                    if (itemIndex > -1) {
                                                        let rawDataItem = window.allRawData[itemIndex];
                                                        let latest = latestDataResponse.data; // Shortcut

                                                        // Map fields from latestData.data to rawDataItem structure
                                                        // This mapping should align with how allRawData is initially populated
                                                        // and what updateTableRowDOM and generatePsHtml consume.
                                                        rawDataItem.QC_check = latest.bom_ing_details.QC_check;
                                                        rawDataItem.QC_check_date = latest.bom_ing_details.QC_check_date_formatted; // Assuming allRawData stores the formatted date
                                                        rawDataItem.processing_state = latest.bom_ing_details.processing_state;
                                                        rawDataItem.ps = latest.bom_ing_details.ps; // bom_ing.ps (general remark for remarks column)
                                                        rawDataItem.sqty = latest.bom_ing_details.sqty; // bom_ing.sqty (total order qty for status calculation)

                                                        // Tooltip remarks from bom_ing
                                                        rawDataItem.QC_ps_qq = latest.bom_ing_details.QC_ps;
                                                        rawDataItem.QC_ps_ng = latest.bom_ing_details.QC_ps2;
                                                        rawDataItem.QC_ps_aod_remark = latest.bom_ing_details.QC_ps_aod;

                                                        // Aggregated quantities from qc_check
                                                        rawDataItem.QC_QQ_sqty = latest.total_qq_qty;
                                                        rawDataItem.QC_ok_sqty = latest.total_ok_qty;

                                                        rawDataItem.latest_QQ_date_formatted = latest.latest_QQ_date_formatted;
                                                        rawDataItem.latest_ok_date_formatted = latest.latest_ok_date_formatted;
                                                        rawDataItem.individual_qc_entries = latest.individual_qc_entries; // For remarks column
                                                        console.log("[allRawData Update] Updated item at index", itemIndex, "for bom_ing_fid", bomIngId);
                                                    } else {
                                                        console.warn("[allRawData Update] bom_ing_fid", bomIngId, "not found in allRawData.");
                                                    }
                                                }
                                                // --- End GEMINI CODE ASSIST: STEP 5 Integration ---

                                                console.log("[Fetch Latest Data Success] Data to update DOM with:", latestDataResponse.data);
                                            } else {
                                                console.error("[Fetch Latest Data Error] Failed to fetch latest data:", latestDataResponse.message);
                                            }
                                        },
                                        error: function(jqXHR, textStatus, errorThrown) {
                                            console.error("[Fetch Latest Data AJAX Error] bom_ing_fid:", bomIngId, "Status:", textStatus, "Error:", errorThrown, jqXHR.responseText);
                                        }
                                    });
                                }
                                // --- END GEMINI CODE ASSIST: STEP 3 ---
                                successCallback(response, form);
                                form.closest('.modal').modal('hide');
                                showTemporaryMessage('紀錄已儲存', 'success');
                            } else {
                                showTemporaryMessage('儲存失敗: ' + response.message, 'error');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error('AJAX Error (Modal):', textStatus, errorThrown, jqXHR.responseText);
                            showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                        },
                        complete: function() {
                            submitButton.prop('disabled', false).val(originalButtonText);
                        }
                    });
                });
            }

            // Attach to "異常" modals
            $('form[action*="_updateQC_check_list_QQ.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href); // 提供基準 URL
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]'); // More robust selector using data attribute
                    if (!targetRow.length) { // Fallback if the above doesn't find it (e.g. if data attribute wasn't added)
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }

                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            console.log("[QQ Success] BOM_ING_FID:", bomIngId);
                            console.log("[QQ Success] Response individual_qc_entries:", JSON.parse(JSON.stringify(response.individual_qc_entries || [])));

                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || [];
                            itemToUpdate.QC_check = response.qc_check; // Overall status from bom_ing
                            itemToUpdate.QC_check_date = response.qc_check_date; // Overall date from bom_ing
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty; // Sum from qc_check
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty; // Sum from qc_check
                            // Ensure these are updated in itemToUpdate as well for consistency
                            itemToUpdate.latest_QQ_date_formatted = response.latest_QQ_date_formatted || '';
                            itemToUpdate.latest_ok_date_formatted = response.latest_ok_date_formatted || '';
                            // item.ps (general bom_ing remark) is not changed by QQ modal.
                            // response.qc_ps, qc_ps2, qc_ps_aod are for the tooltip button.

                            let newStatusDisplayHtml = '';
                            if (response.qc_check === 'QQ') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_y"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else if (response.qc_check === 'ok') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_green"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else {
                                var statusParts = [];
                                var totalOrderQtyForRow = parseFloat(itemToUpdate.sqty) || 0; // bom_ing.sqty
                                var totalCheckedQtyForRow = (parseFloat(response.total_qq_qty) || 0) + (parseFloat(response.total_ok_qty) || 0);
                                var latestQqDateFromResponse = he(response.latest_QQ_date_formatted || '');
                                var latestOkDateFromResponse = he(response.latest_ok_date_formatted || '');

                                if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow >= totalOrderQtyForRow) {
                                    // Fully checked or over-checked
                                    if (parseFloat(response.total_qq_qty) > 0 && parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${he(String(response.total_qq_qty))}</small>`);
                                        statusParts.push(`<span class="circle_green"></span><small>${he(String(response.total_ok_qty))}</small>`);
                                    } else if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${latestQqDateFromResponse}</small>`);
                                    } else if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_green"></span><small>${latestOkDateFromResponse}</small>`);
                                    } else {
                                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                                    }
                                } else {
                                    // Partially checked or totalOrderQty is 0
                                    if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow < totalOrderQtyForRow && totalCheckedQtyForRow > 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                    if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push('<span class="circle_y"></span><small>' + he(String(response.total_qq_qty)) + '</small>');
                                    }
                                    if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push('<span class="circle_green"></span><small>' + he(String(response.total_ok_qty)) + '</small>');
                                    }
                                    if (statusParts.length === 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                }
                                newStatusDisplayHtml = '<div class="qc-flex">' + statusParts.join('&emsp;') + '</div>';
                            }


                            let newPsHtml = generatePsHtml(itemToUpdate);
                            let newQcCheckValueForFilter = response.qc_check || '';

                            var dtRow = dataTableInstance.row(targetRow);
                            // var oldPsHtml = dtRow.node() ? dtRow.data()[9] : "N/A (dtRow node not found)"; // Already logged
                            // console.log("[QQ Success] Old HTML for remarks cell:", oldPsHtml);
                            // console.log("[QQ Success] New HTML for remarks cell:", newPsHtml);

                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml); // Status column (data index 1)
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml); // Remarks column (data index 9)
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter); // Hidden QC Check Raw (data index 12)

                                // Redraw the row
                                // dtRow.invalidate('data').draw(false); // Alternative: invalidate based on data source
                                dataTableInstance.row(dtRow.index()).draw(false); // Redraw the specific row by its index

                                // console.log("[QQ Success] Row updated and redrawn for bom_ing_fid " + bomIngId);
                                // The following lines to update attributes and tooltip button remain important


                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            // Attach to "驗退" modals
            $('form[action*="_updateQC_check_list_ng.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href); // 提供基準 URL
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                    if (!targetRow.length) { // Fallback
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }
                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            // For NG, individual_qc_entries are not directly managed by this modal type in the same way as QQ/OK.
                            // The bom_ing.QC_check becomes 'ng'.
                            // The bom_ing.QC_ps2 is updated with the NG remark.
                            // We still need to refresh individual_qc_entries if they could have been affected or to ensure consistency.
                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || []; // Assuming backend sends this
                            itemToUpdate.QC_check = response.qc_check; // Should be 'ng'
                            itemToUpdate.QC_check_date = response.qc_check_date;
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty; // Fetch related sums
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty;
                            // itemToUpdate.ps (bom_ing.ps) is not changed by NG modal.
                            // response.qc_ps2 is the NG remark for the tooltip.

                            let newStatusDisplayHtml = '';
                            if (response.qc_check === 'ng') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_red"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else {
                                // Fallback if qc_check is not 'ng' (should not happen for this modal's success)
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_gray"></span><small>待驗</small></div>';
                            }

                            let newPsHtml = generatePsHtml(itemToUpdate); // Regenerate remarks
                            let newQcCheckValueForFilter = 'ng';

                            var dtRow = dataTableInstance.row(targetRow);
                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                // Column indices: 1 for Status, 9 for Remarks, 12 for hidden QC_check_raw
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml);
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml);
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter);

                                // Redraw the row
                                dataTableInstance.row(dtRow.index()).draw(false);

                                // Update attributes and tooltip button
                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            // Attach to "允收" modals
            $('form[action*="_updateQC_check_list_ok.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href);
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                    if (!targetRow.length) {
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }

                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || [];
                            itemToUpdate.QC_check = response.qc_check;
                            itemToUpdate.QC_check_date = response.qc_check_date;
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty; // Sum from qc_check
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty; // Sum from qc_check
                            // Ensure these are updated in itemToUpdate as well
                            itemToUpdate.latest_QQ_date_formatted = response.latest_QQ_date_formatted || '';
                            itemToUpdate.latest_ok_date_formatted = response.latest_ok_date_formatted || '';

                            let newStatusDisplayHtml = '';
                            if (response.qc_check === 'ok') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_green"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else if (response.qc_check === 'QQ') {
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_y"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            } else {
                                var statusParts = [];
                                var totalOrderQtyForRow = parseFloat(itemToUpdate.sqty) || 0;
                                var totalCheckedQtyForRow = (parseFloat(response.total_qq_qty) || 0) + (parseFloat(response.total_ok_qty) || 0);
                                var latestQqDateFromResponse = he(response.latest_QQ_date_formatted || '');
                                var latestOkDateFromResponse = he(response.latest_ok_date_formatted || '');

                                if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow >= totalOrderQtyForRow) {
                                    // Fully checked or over-checked
                                    if (parseFloat(response.total_qq_qty) > 0 && parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${he(String(response.total_qq_qty))}</small>`);
                                        statusParts.push(`<span class="circle_green"></span><small>${he(String(response.total_ok_qty))}</small>`);
                                    } else if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push(`<span class="circle_y"></span><small>${latestQqDateFromResponse}</small>`);
                                    } else if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push(`<span class="circle_green"></span><small>${latestOkDateFromResponse}</small>`);
                                    } else {
                                        statusParts.push(`<span class="circle_gray"></span><small>待驗</small>`);
                                    }
                                } else {
                                    // Partially checked or totalOrderQty is 0
                                    if (totalOrderQtyForRow > 0 && totalCheckedQtyForRow < totalOrderQtyForRow && totalCheckedQtyForRow > 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                    if (parseFloat(response.total_qq_qty) > 0) {
                                        statusParts.push('<span class="circle_y"></span><small>' + he(String(response.total_qq_qty)) + '</small>');
                                    }
                                    if (parseFloat(response.total_ok_qty) > 0) {
                                        statusParts.push('<span class="circle_green"></span><small>' + he(String(response.total_ok_qty)) + '</small>');
                                    }
                                    if (statusParts.length === 0) {
                                        statusParts.push('<span class="circle_gray"></span><small>待驗</small>');
                                    }
                                }
                                newStatusDisplayHtml = '<div class="qc-flex">' + statusParts.join('&emsp;') + '</div>';
                            }

                            let newPsHtml = generatePsHtml(itemToUpdate);
                            let newQcCheckValueForFilter = response.qc_check || '';

                            var dtRow = dataTableInstance.row(targetRow);
                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                // Column indices: 1 for Status, 9 for Remarks, 12 for hidden QC_check_raw
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml);
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml);
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter);

                                // Redraw the row
                                dataTableInstance.row(dtRow.index()).draw(false);

                                // Update attributes and tooltip button
                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            // Attach to "特採" modals (New)
            $('form[action*="_updateQC_check_list_AOD.php"]').each(function() {
                handleModalFormSubmit($(this), function(response, form) {
                    var actionUrl = form.attr('action');
                    var fullUrl = new URL(actionUrl, window.location.href);
                    var bomIngId = fullUrl.searchParams.get("bi");
                    var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                    if (!targetRow.length) {
                        targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                            return $(this).data('bom-ing-fid') === bomIngId;
                        });
                    }
                    if (targetRow.length) {
                        let itemToUpdate = allRawData.find(item => item.bom_ing_fid === bomIngId);
                        if (itemToUpdate) {
                            // For AOD, bom_ing.QC_check becomes 'AOD'.
                            // bom_ing.QC_ps_aod is updated.
                            itemToUpdate.individual_qc_entries = response.individual_qc_entries || []; // Assuming backend sends this
                            itemToUpdate.QC_check = response.qc_check; // Should be 'AOD'
                            itemToUpdate.QC_check_date = response.qc_check_date;
                            itemToUpdate.QC_QQ_sqty = response.total_qq_qty;
                            itemToUpdate.QC_ok_sqty = response.total_ok_qty;
                            // itemToUpdate.ps (bom_ing.ps) is not changed by AOD modal.
                            // response.qc_ps_aod is the AOD remark for the tooltip.

                            let newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_y"></span><small>' + he(response.qc_check_date || '') + '</small></div>';
                            if (response.qc_check !== 'AOD') { // Fallback if status is not AOD after AOD operation (should not happen)
                                newStatusDisplayHtml = '<div class="qc-flex"><span class="circle_gray"></span><small>待驗</small></div>';
                            }

                            let newPsHtml = generatePsHtml(itemToUpdate);
                            let newQcCheckValueForFilter = 'AOD';

                            var dtRow = dataTableInstance.row(targetRow);
                            if (dtRow.node()) {
                                // Update DataTables' internal data store for each affected cell
                                // Column indices: 1 for Status, 9 for Remarks, 12 for hidden QC_check_raw
                                dataTableInstance.cell(dtRow.index(), 1).data(newStatusDisplayHtml);
                                dataTableInstance.cell(dtRow.index(), 9).data(newPsHtml);
                                dataTableInstance.cell(dtRow.index(), 12).data(newQcCheckValueForFilter);

                                // Redraw the row
                                dataTableInstance.row(dtRow.index()).draw(false);

                                // Update attributes and tooltip button
                                targetRow.attr('data-qc-check', newQcCheckValueForFilter);
                                updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);
                            }
                        }
                    }
                });
            });

            function updateQcPsButton(targetRow, qcPs, qcPs2, qcPsAod) {
                var qcNoteCell = targetRow.find('td[name="d_id"]');
                // Make selector more specific to the QC remark button
                var qcNoteButton = qcNoteCell.find('button.qc-remark-button');
                var tooltipLines = [];

                var qcPsText = (qcPs && typeof qcPs === 'string') ? qcPs.trim() : ''; // For "異常"
                var qcPs2Text = (qcPs2 && typeof qcPs2 === 'string') ? qcPs2.trim() : ''; // For "驗退"
                var qcPsAodText = (qcPsAod && typeof qcPsAod === 'string') ? qcPsAod.trim() : ''; // For "特採"

                if (qcPsAodText !== '') {
                    tooltipLines.push("特採：" + qcPsAodText);
                } // Order: 特採
                if (qcPsText !== '') {
                    tooltipLines.push("異常：" + qcPsText);
                } // Then 異常
                if (qcPs2Text !== '') {
                    tooltipLines.push("驗退：" + qcPs2Text);
                } // Then 驗退

                var finalTooltipTitle = '';
                if (tooltipLines.length > 0) {
                    finalTooltipTitle = tooltipLines.join('\n'); // Join with a newline for multi-line tooltips
                }

                if (finalTooltipTitle !== '') { // If there's any content for the tooltip
                    if (qcNoteButton.length === 0) {
                        var $newButton = $('<button type="button" class="btn btn-xs btn-default qc-remark-button" data-toggle="tooltip" data-placement="right"></button>')
                            .attr('title', finalTooltipTitle)
                            .attr('data-remark-content', finalTooltipTitle)
                            .text('QC備註');
                        var $anchor = qcNoteCell.find('a');
                        if ($anchor.length) {
                            $anchor.append(' ').append($newButton); // Append with a leading space
                        } else {
                            // If no anchor, append directly to the cell, but this case might not occur based on your HTML structure
                            qcNoteCell.append(' ').append($newButton);
                        }
                        $newButton.tooltip(); // Initialize Bootstrap tooltip on the newly created button
                    } else {
                        qcNoteButton.attr('title', finalTooltipTitle);
                        qcNoteButton.attr('data-remark-content', finalTooltipTitle); // Add this line
                    }
                } else {
                    // All remarks are empty, remove the button
                    if (qcNoteButton.length > 0) {
                        qcNoteButton.tooltip('destroy'); // Destroy Bootstrap tooltip before removing
                        qcNoteButton.remove();
                    }
                }
            }

            function showTemporaryMessage(message, type) {
                var alertClass = (type === 'success') ? 'alert-success' : 'alert-danger';
                var messageDiv = $('<div class="alert ' + alertClass + ' fade in alert-dismissable" style="position: fixed; top: 20px; right: 20px; z-index: 1051; min-width: 200px;">' + // Increased z-index
                    '<a href="#" class="close" data-dismiss="alert" aria-label="close" title="close">×</a>' +
                    message + '</div>');
                $('body').append(messageDiv);
                messageDiv.fadeIn().delay(3000).fadeOut(function() {
                    $(this).remove();
                });
            }

            // ⭐ 合併：當「允收」或「異常」Modal 顯示時，動態載入其對應的詳細資料
            $('#modals-container').on('shown.bs.modal', function(event) {
                var modal = $(event.target);
                var modalId = modal.attr('id');

                // --- 處理「允收 (OK)」Modal ---
                if (modalId && modalId.startsWith('myModal_ok_')) {
                    var bomIngFid = modal.attr('id').replace('myModal_ok_', '');
                    var $form = modal.find('form');
                    var sqty = parseFloat($form.data('sqty')) || 0; // Get total sqty from form's data attribute
                    var wrapperId = '#ok-rows-wrapper_' + bomIngFid;
                    var $wrapper = $(wrapperId);
                    $wrapper.html('<div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px; text-align:center;"><div style="flex: 1;">載入中...</div></div>');
            
                    $.ajax({
                        url: '../../src/store/_updateQC_check_list_ok.php',
                        type: 'GET',
                        data: {
                            action: 'fetch_ok_details',
                            bi: bomIngFid
                        },
                        dataType: 'json',
                        success: function(response) {
                            $wrapper.empty();
            
                            if (response.success && response.data) {
                                if (response.data.length > 0) {
            
                                    var isPrivilegedUser = (window.currentUserStatus == 9 || window.currentUserStatus == 50 || window.currentUserStatus == 51);
                                    response.data.forEach(function(record, index) { // Removed 'array' as it's not strictly needed here for the new logic
                                        var actionButtonHtml = '';
                                        var readonlyAttribute = ''; // 移除唯讀屬性，讓輸入框始終可編輯
            
                                        // If it's the first record (index 0), it gets both "plus" and "minus" buttons.
                                        // Otherwise, it only gets a "minus" button.
                                        if (index === 0) {
                                            actionButtonHtml =
                                                '<button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                                                '<button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button>';
                                        } else {
                                            actionButtonHtml =
                                                '<button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button>';
                                        }
            
                                        var newRowHtml = `
                                            <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                                <div style="width: 100px; margin-right: 10px; flex-shrink: 0;">
                                                    <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${he(record.QC_ok_sqty || '')}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量" ${readonlyAttribute}>
                                                    <input type="hidden" name="qc_check_id[]" value="${he(record.qc_check_id || '')}">
                                                </div>
                                                <div style="flex-grow: 1; margin-right: 10px;">
                                                    <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註" ${readonlyAttribute}>${he(record.QC_ps_ok || '')}</textarea>
                                                </div>
                                                <div style="width: 80px; margin-right: 10px; flex-shrink: 0; padding-top: 7px;" class="ok-check-date">
                                                    ${he(record.QC_check_date_formatted || '')}
                                                </div>
                                                <div style="width: 80px; flex-shrink: 0; text-align: left;" class="ok-action-buttons">${actionButtonHtml}</div>
                                            </div>`;
                                        $wrapper.append(newRowHtml);
                                    });
                                } else { // No existing 'ok' records, add one blank row
                                    var sqty = parseFloat($form.data('sqty')) || 0; // Use total sqty as default for blank row
                                    var newRowHtml = `
                                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                                <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${sqty}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                <!-- No qc_check_id for new rows initially -->
                                                <input type="hidden" name="qc_check_id[]" value="">
                                            </div>
                                            <div style="flex: 1; margin-right: 10px;">
                                                <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註"></textarea>                                            </div>
                                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px;" class="ok-check-date"></div>
                                            <div style="flex: 0 0 80px; text-align: left;" class="ok-action-buttons">
                                                <button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                            </div></div>`;
                                    // The initial blank row should not have a date display as it's not yet saved.
                                    $wrapper.append(newRowHtml);
                                }
                            } else {
                                $wrapper.html('<div class="ok-entry-row" style="text-align:center;"><div style="flex:1; color:red;">載入失敗: ' + he(response.message) + '</div></div>');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            $wrapper.html('<div class="ok-entry-row" style="text-align:center;"><div style="flex:1; color:red;">請求失敗: ' + he(textStatus) + '</div></div>');
                            console.error("Error fetching OK details:", textStatus, errorThrown);
                        }
                    });
                }
            
                // --- 處理「異常 (QQ)」Modal ---
                else if (modalId && modalId.startsWith('myModal_qq_')) {
                    var bomIngFid = modal.attr('id').replace('myModal_qq_', '');
                    var $form = modal.find('form');
                    var wrapperId = '#abnormal-rows-wrapper_' + bomIngFid;
                    var $wrapper = $(wrapperId);
            
                    $wrapper.html('<div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px; text-align:center;"><div style="flex: 1;">載入中...</div></div>');
                    modal.find('input[name="abnormal_order_no"]').val('');
            
                    $.ajax({
                        url: '../../src/store/_updateQC_check_list_QQ.php',
                        type: 'GET',
                        data: {
                            action: 'fetch_qq_details',
                            bi: bomIngFid,
                            id: currentUserId
                        },
                        dataType: 'json',
                        success: function(response) {
                            $wrapper.empty();
                            if (response.success && response.data && Array.isArray(response.data)) {
                                var records = response.data;
                                var firstAbnormalOrderNo = null;
            
                                if (records.length > 0) { // If there are existing QQ records
                                    if (records[0].abnormal_order_no) {
                                        firstAbnormalOrderNo = records[0].abnormal_order_no;
                                    }
            
                                    records.forEach(function(record, index) {
                                        var newRowHtml = `
                                            <div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                                <div style="flex: 0 0 100px; margin-right: 10px;">
                                                    <input type="number" class="form-control abnormal-qty-input" name="qq_total_qty[]" value="${he(record.QC_QQ_sqty || '')}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                    <input type="hidden" name="qc_check_id[]" value="${he(record.qc_check_id || '')}">
                                                </div>
                                                <div style="flex: 1; margin-right: 10px;">
                                                    <textarea rows="1" class="form-control abnormal-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="請填寫異常原因">${he(record.QC_ps || '')}</textarea>
                                                </div>
                                                <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px; font-size:0.9em;" class="abnormal-check-date">
                                                    ${he(record.QC_check_date_formatted || '')}
                                                </div>
                                                <div style="flex: 0 0 110px; text-align: left; display:flex; gap:3px; flex-wrap:wrap;" class="abnormal-action-buttons">
                                                    ${index === 0 ?
                                                        '<button type="button" class="btn btn-warning btn-xs add-abnormal-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                                                        '<button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>' :
                                                        '<button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>'}
                                                    ${record.qc_check_id && record.QC_QQ_sqty && record.QC_ps ?
                                                        '<button type="button" class="btn-open-ncr" title="開立品質異常單" data-fid="' + bomIngFid + '"><i class="fa fa-file-text-o"></i> 開異常單</button>' : ''}
                                                </div>
                                            </div>`;
                                        $wrapper.append(newRowHtml);
                                    });
                                } else {
                                    var blankRowHtml = `<!-- If no existing QQ records, add one blank row -->
                                        <div class="abnormal-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                                <input type="number" class="form-control abnormal-qty-input" name="qq_total_qty[]" value="" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                                <input type="hidden" name="qc_check_id[]" value="">
                                            </div>
                                            <div style="flex: 1; margin-right: 10px;">
                                                <textarea rows="1" class="form-control abnormal-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="請填寫異常原因"></textarea>
                                            </div>
                                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px; font-size:0.9em;" class="abnormal-check-date"></div>
                                            <div style="flex: 0 0 110px; text-align: left; display:flex; gap:3px; flex-wrap:wrap;" class="abnormal-action-buttons">
                                                <button type="button" class="btn btn-warning btn-xs add-abnormal-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                                <button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>
                                            </div><!-- The initial blank row should have both buttons -->
                                        </div>`;
                                    $wrapper.append(blankRowHtml);
                                }
                                modal.find('input[name="abnormal_order_no"]').val(he(firstAbnormalOrderNo || ''));
                            } else {
                                $wrapper.html('<div class="abnormal-entry-row" style="text-align:center;"><div style="flex:1; color:red;">載入失敗: ' + he(response.message || '未知錯誤') + '</div></div>');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            $wrapper.html('<div class="abnormal-entry-row" style="text-align:center;"><div style="flex:1; color:red;">請求失敗: ' + he(textStatus) + '</div></div>');
                            console.error("Error fetching QQ details:", textStatus, errorThrown, jqXHR.responseText);
                        }
                    });
                }
            });

            // --- QC Remark Popup Logic ---
            var $qcRemarkPopup = $('#qcRemarkPopup');
            var $qcRemarkPopupContent = $('#qcRemarkPopupContent');
            var currentQcRemarkButton = null;

            // Handle click on QC Remark buttons
            $(document).on('click', '.qc-remark-button', function(event) {
                event.preventDefault();
                event.stopPropagation();

                var $button = $(this);
                var remarkContent = $button.data('remark-content');

                if ($qcRemarkPopup.is(':visible') && currentQcRemarkButton === this) {
                    $qcRemarkPopup.hide();
                    currentQcRemarkButton = null;
                } else {
                    $qcRemarkPopupContent.html(remarkContent ? remarkContent.replace(/\n/g, '<br>') : '');

                    var buttonOffset = $button.offset();
                    var buttonHeight = $button.outerHeight();

                    $qcRemarkPopup.css({
                        top: buttonOffset.top + buttonHeight + 5,
                        left: buttonOffset.left,
                        display: 'block'
                    });
                    currentQcRemarkButton = this;
                }
            });

            // Close popup when clicking outside
            $(document).on('click', function(event) {
                if ($qcRemarkPopup.is(':visible') && !$(event.target).closest('#qcRemarkPopup').length && event.target !== currentQcRemarkButton && !$(event.target).closest('.qc-remark-button').is(currentQcRemarkButton)) {
                    $qcRemarkPopup.hide();
                    currentQcRemarkButton = null;
                }
            });
            $qcRemarkPopup.on('click', function(event) {
                event.stopPropagation();
            });

            // --- Clear Textarea Button for Modals ---
            // This handler is for AOD and NG modals, where "Clear and Save" means clearing the remark and saving.
            // This handler is now AJAX-based to prevent page reload and update UI selectively.
            $(document).on('click', '.clear-textarea-btn', function() {
                var $button = $(this);
                var $modalContent = $button.closest('.modal-content');
                var $textarea = $modalContent.find('textarea[name="QCmessage"]');
                var $abnormalQtyInput = $modalContent.find('input[name="abnormal_total_qty"]'); // Find abnormal quantity input
                var $form = $modalContent.find('form');
                var originalButtonText = $button.text();

                // Specifically for AOD and NG modals, this button clears the main textarea.
                // It does NOT clear quantity inputs in these modals as they are separate.
                $textarea.val(''); // Clear the textarea

                // For AOD and NG, the quantity inputs are separate and not part of this "clear textarea" action.
                // If $abnormalQtyInput is specific to QQ modal, it shouldn't be here.
                // Let's assume this generic handler is NOT for QQ modal's "clear all entries".
                // $abnormalQtyInput.val(''); // This line might be incorrect for a generic handler.

                $button.prop('disabled', true).text('處理中...');


                var formData = $form.serializeArray(); // Get form data as array
                formData.push({
                    name: "clear_remark_only",
                    value: "1"
                }); // Add flag

                $.ajax({
                    url: $form.attr('action'),
                    type: 'POST',
                    data: $.param(formData), // Serialize the array to query string
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var actionUrl = $form.attr('action');
                            var fullUrl = new URL(actionUrl, window.location.href);
                            var bomIngId = fullUrl.searchParams.get("bi");
                            var targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                            if (!targetRow.length) {
                                // Fallback if data-attribute not found (shouldn't happen with current setup)
                                targetRow = dataTableInstance.rows().nodes().to$().filter(function() {
                                    return $(this).data('bom-ing-fid') === bomIngId;
                                });
                            }

                            if (targetRow.length) {
                                // For AOD/NG clear, the main status circle (QC_check) is NOT changed by backend.
                                // Only the specific remark (QC_ps_aod or QC_ps2) is cleared.
                                // So, we only need to update the tooltip button.
                                // The backend response for AOD/NG clear_remark_only=1 returns the updated set of remarks.

                                // Fetch full details to ensure all parts of the row are consistent,
                                // especially if other parts of the system could have changed the row.
                                $.ajax({
                                    url: '../../src/store/_fetch_qc_row_details.php',
                                    type: 'GET',
                                    data: {
                                        bi: bomIngId
                                    },
                                    dataType: 'json',
                                    success: function(fetchResponse) {
                                        if (fetchResponse.success && fetchResponse.data) {
                                            updateTableRowDOM(targetRow, fetchResponse.data);
                                            // Update allRawData (simplified, assumes direct mapping for relevant fields)
                                            var itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                            if (itemIndex > -1) {
                                                Object.assign(window.allRawData[itemIndex], fetchResponse.data.bom_ing_details, {
                                                    individual_qc_entries: fetchResponse.data.individual_qc_entries,
                                                    total_qq_qty: fetchResponse.data.total_qq_qty,
                                                    total_ok_qty: fetchResponse.data.total_ok_qty,
                                                    latest_QQ_date_formatted: fetchResponse.data.latest_QQ_date_formatted,
                                                    latest_ok_date_formatted: fetchResponse.data.latest_ok_date_formatted
                                                });
                                            }
                                        }
                                    }
                                });
                                // The line below was for the old direct update, now handled by fetch + updateTableRowDOM
                                // updateQcPsButton(targetRow, response.qc_ps, response.qc_ps2, response.qc_ps_aod);

                                // The following lines related to abnormal_total_qty are specific to QQ modal and should not be in this generic handler.
                                // $form.data('initial-abnormal-qty', response.QC_QQ_sqty === null ? 0 : response.QC_QQ_sqty);
                                // $form.find('input[name="abnormal_total_qty"]').val(response.QC_QQ_sqty === null ? '' : response.QC_QQ_sqty);

                                // DO NOT update status circle or data-qc-check attribute here for AOD/NG clear
                                // because QC_check and QC_check_date were not changed by this action.

                                if (dataTableInstance) {
                                    var dtRow = dataTableInstance.row(targetRow);
                                    dtRow.invalidate('dom'); // Invalidate based on current DOM
                                    if (typeof dataTableInstance.responsive === 'object' && dataTableInstance.responsive.hasHidden && dataTableInstance.responsive.hasHidden()) {
                                        dataTableInstance.responsive.recalc();
                                    }
                                    dataTableInstance.draw(false);
                                }
                            }
                            showTemporaryMessage('備註已清除並儲存', 'success');
                        } else {
                            showTemporaryMessage('清除備註失敗: ' + response.message, 'error');
                            // Textarea remains cleared as per user action
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) { // This is for the POST to clear remark
                        console.error('AJAX Error (Clear Remark):', textStatus, errorThrown, jqXHR.responseText);
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                        // Textarea remains cleared
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });

            // --- QR Code Icon Tooltip - Immediate Hide Logic ---
            // Event delegation for QR code button tooltips to show/hide immediately
            $('#qc-data-table tbody').on('mouseenter', '.qr-code-btn-tooltip', function() {
                $(this).tooltip({ // Initialize on hover with manual trigger
                    trigger: 'manual',
                    container: 'body' // Appends tooltip to body, helps with positioning
                }).tooltip('show');
            }).on('mouseleave', '.qr-code-btn-tooltip', function() {
                // Hide tooltip immediately on mouse leave
                if ($(this).data('bs.tooltip')) { // Check if tooltip was initialized
                    $(this).tooltip('hide');
                }
            });

            // QR Code Modal: Event listeners for dynamic calculation and buttons
            $('#modals-container').on('input change', '.qty-per-unit, .packaging-type', function() {
                const $modal = $(this).closest('.modal');
                if (!$modal.find('.qty-per-unit').length) return; // Only proceed if it's the QR code modal

                const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
                const $qtyPerUnitInput = $modal.find('.qty-per-unit');
                const $packagingTypeSelect = $modal.find('.packaging-type');
                const $calculationResultDiv = $modal.find('.calculation-result');

                let qtyPerUnit = parseFloat($qtyPerUnitInput.val()) || 0;
                const packagingType = $packagingTypeSelect.val();

                if (qtyPerUnit > totalQty && totalQty > 0) {
                    alert("每單位數量 (" + qtyPerUnit + ") 不可超過總數 (" + totalQty + ")。");
                    $qtyPerUnitInput.val(totalQty); // Optionally reset to max allowed or leave as is
                    qtyPerUnit = totalQty; // Re-assign for calculation
                }

                if (qtyPerUnit > 0 && totalQty > 0) {
                    const numPackages = Math.floor(totalQty / qtyPerUnit);
                    // Update the calculation result text
                    $calculationResultDiv.text(`共 ${qtyPerUnit} ${packagingType}`);
                } else {
                    // If qtyPerUnit is not valid, show placeholder
                    $calculationResultDiv.text(`共 ? ${packagingType}`);
                }
            });

            $('#modals-container').on('click', '.clear-button', function() {
                const $modal = $(this).closest('.modal');
                if (!$modal.find('.qty-per-unit').length) return;

                $modal.find('.qty-per-unit').val('');
                $modal.find('.packaging-type').prop('disabled', false).trigger('change');
                $modal.find('.generate-qrcode-button').show();
                $modal.find('.direct-print-qrcode-button').show(); // Ensure print button is visible
                $modal.find('.qrcode-display-area').hide().html('<p>QR Code 預留位置</p>'); // Hide and reset QR display
            });

            // Generate QR Code Button
            $('#modals-container').on('click', '.generate-qrcode-button', function() {
                const $modal = $(this).closest('.modal');
                const $qtyPerUnitInput = $modal.find('.qty-per-unit');
                // const $packagingTypeSelect = $modal.find('.packaging-type'); // No longer directly used for display
                // const $qrCodeDisplayArea = $modal.find('.qrcode-display-area'); // No longer used for preview
                const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
                // const packagingTypeVal = $modal.find('.packaging-type option:selected').text(); // No longer directly used for display
                const qtyPerUnitVal = $modal.find('.qty-per-unit').val() || "未輸入";
                const userInputTotalBoxes = parseFloat($qtyPerUnitInput.val()) || 0; // User's input for total boxes
                const bomForQr = $modal.find('.modal-body').data('bom');

                if (!$modal.find('.qty-per-unit').length) return;

                if (userInputTotalBoxes <= 0 || qtyPerUnitVal === "未輸入") {
                    alert("請先輸入有效的總箱數。"); // Changed alert message
                    return;
                }
                // Removed validation: if (userInputTotalBoxes > totalQty && totalQty > 0)
                // It's okay if total boxes > total items.
                // Make inputs readonly
                // $qtyPerUnitInput.prop('readonly', true); // Readonly state handled by print flow
                // $packagingTypeSelect.prop('disabled', true); // Disabled state handled by print flow

                // Hide the clear button
                // $modal.find('.clear-button').hide(); // Clear button remains visible

                // Button visibility handled by direct-print-qrcode-button logic

                // Construct QR Code URL
                const qrUrl = `${location.origin}/EGsystem/views/pm/schedule_T5.php?b=${encodeURIComponent(bomForQr)}`;

                // Construct URL for the generate_qrcode.php script
                const generateQrCodePhpUrl = `../../views/QC/generate_qrcode.php?text=${encodeURIComponent(qrUrl)}`;

                // Correctly get bom_ing_fid from the modal's ID to form the qrCodeContainerId
                const modalId = $modal.attr('id'); // e.g., "myModal_qrcode_actualBomIngFid"
                const bomIngFidValue = modalId.substring(modalId.lastIndexOf('_') + 1); // Extracts the part after the last underscore
                const qrCodeContainerId = `qrcode_image_container_${bomIngFidValue}`;

                // Preview is skipped. The direct-print-qrcode-button will handle printing.
                // For now, this button might not be needed if direct-print-qrcode-button does everything.
                // If it's still used, it should probably just call the direct print logic.
                // For simplicity, let's assume direct-print-qrcode-button is the main one.
                // This .generate-qrcode-button might be removed or repurposed.
                // If this button is still intended to be the primary print button, its logic needs to be merged
                // with the .direct-print-qrcode-button logic.

                // For now, let's make this button also trigger the direct print.
                $modal.find('.direct-print-qrcode-button').click();
            });

            // Direct Print QR Code Button (replaces the old print and generate logic)
            $('#modals-container').on('click', '.direct-print-qrcode-button', function() {
                const $modal = $(this).closest('.modal');
                const bomForQr = $modal.find('.modal-body').data('bom');
                const dIdFromModal = $modal.find('.modal-body').data('d-id') || 'N/A';
                const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
                const packagingTypeVal = $modal.find('.packaging-type option:selected').text();
                const userInputTotalBoxes = parseFloat($modal.find('.qty-per-unit').val()) || 0; // Total boxes from user input

                // Construct QR Code URL for printing
                const qrUrlForPrint = `${location.origin}/EGsystem/views/pm/schedule_T5.php?b=${encodeURIComponent(bomForQr)}`;
                const generateQrCodePhpUrlForPrint = `../../views/QC/generate_qrcode.php?text=${encodeURIComponent(qrUrlForPrint)}`;
                const qrCodeForPrintHtml = `<img src="${generateQrCodePhpUrlForPrint}" alt="QR Code" class="qr-code-image">`;

                if (userInputTotalBoxes <= 0) {
                    alert("請輸入有效的總箱數才能列印。"); // Changed alert message
                    return;
                }

                const totalPagesToPrint = userInputTotalBoxes; // Use user input directly

                if (totalQty < 0) { // totalQty can be 0, but not negative
                    alert("總數量為0或無效，無法列印。");
                    return;
                }

                const today = new Date();
                const dateString = `${today.getFullYear()}.${String(today.getMonth() + 1).padStart(2, '0')}.${String(today.getDate()).padStart(2, '0')}`;

                let allPagesHtml = ''; // Accumulator for all label HTML
                for (let currentPage = 1; currentPage <= totalPagesToPrint; currentPage++) {
                    // Calculate quantity for the current box
                    // For now, it's not used in the visible label.

                    let printHtml = `
                        <html>
                        <head>
                            <title>列印 - ${he(bomForQr)} - 箱號 ${currentPage}/${totalPagesToPrint}</title>
                            <style>
                                @page {
                                    margin-top: 0mm; /* Align print content to the top of the page */
                                    margin-bottom: 0mm; /* Optional: also remove bottom margin */
                                    size: 70mm 50mm; /* Explicitly set page size */
                                }
                                body { 
                                    font-family: Arial, "微軟正黑體", "Microsoft JhengHei", sans-serif;
                                    margin: 0; /* Remove body margin for precise label control */
                                    font-size: 8pt; /* Reduced base font size for smaller label */
                                }
                                .print-container { 
                                    width: 70mm; /* Target label width */
                                    height: 50mm; /* Target label height */
                                    border: none; /* No border for actual printing */
                                    padding: 2mm; /* Reduced padding for smaller label */
                                    box-sizing: border-box; 
                                    overflow: hidden; /* Prevent content from spilling out */
                                    page-break-after: always; /* Ensure each label is on a new page */
                                }
                                .part-number-row { 
                                    font-size: 12pt; /* Reduced font size */
                                    font-weight: bold;
                                    text-align: left; /* Spans both columns, aligned left */
                                    margin-bottom: 0.5mm; /* Further reduced spacing */
                                    padding-bottom: 0mm; /* Reduced spacing */
                                    /* border-bottom: 1px solid black; Removed */
                                }
                                .content-table { 
                                    width: 100%; 
                                    border-collapse: collapse; 
                                }
                                .content-table td { 
                                    padding: 1mm; 
                                    vertical-align: top; 
                                }
                                .left-col { 
                                    width: 50%; Removed for auto-width
                                    padding-right: 0mm; /* Space between text and dashed line */
                                    /* border-right: 0px dashed #555; Removed */
                                    text-align: left;
                                    font-size: 10pt; 
                                }
                                .right-col { 
                                    width: 50%; Removed for auto-width
                                    padding-left: 0mm; /* Space between dashed line and QR code */
                                    text-align: left; /* 水平靠左 QR Code */
                                    vertical-align: top; /* 垂直靠上 QR Code */
                                    margin: 0;  /* For centering */
                                }
                                .label { font-weight: bold; font-size: 9pt; } /* Increased font size */
                                .info-line { line-height: 1.5; font-size: 9pt; } /* Increased font size */
                                .info-line:not(:last-child) {
                                    margin-bottom: 0.5mm; /* Reduced margin */
                                }
                                .company-footer { 
                                    margin-top: 0mm; /* Removed top margin to stick to content above */
                                    padding-top: 1mm; /* Reduced padding */
                                    font-size: 11pt; 
                                    font-weight: bold; /* Added bold font weight */
                                    text-align: justify; text-justify: inter-word; /* Justified alignment */
                                    border-top: 1px solid black; /* Line for merged footer */
                                }
                                .qr-code-image {
                                    max-width: 90%; /* Maximize width within its column */
                                    height: auto;    /* Maintain aspect ratio */
                                    display: block;  /* For centering */
                                    margin: 0 0;  /* For centering */
                                }
                            </style>
                        </head>
                        <body>
                            <div class="print-container">
                                <div class="part-number-row">料號：${he(dIdFromModal)}</div>
                                <table class="content-table">
                                    <tr>
                                        <td class="left-col">
                                            <div class="info-line"><span class="label">製令：</span>${he(bomForQr)}</div>
                                            <div class="info-line"><span class="label">總數：</span>${totalQty}</div>
                                            <div class="info-line"><span class="label">容器：</span>${he(packagingTypeVal)}</div>
                                            <div class="info-line"><span class="label">箱號：</span>${currentPage} / ${totalPagesToPrint}</div>
                                            <div class="info-line"><span class="label">日期：</span>${dateString}</div>
                                        </td>
                                        <td class="right-col">
                                            ${qrCodeForPrintHtml}
                                        </td>
                                    </tr>
                                </table>
                                <div class="company-footer">
                                    超正齒輪科技有限公司 2-QA-01-02
                                </div>
                            </div>
                    `;
                    allPagesHtml += printHtml; // Append current label's HTML
                }

                // Open one print window with all labels
                if (allPagesHtml) {
                    let printWindow = window.open('', '_blank', 'height=600,width=1000'); // Increased window size
                    printWindow.document.write(`
                        <html>
                        <head>
                            <title>列印預覽 - ${he(bomForQr)}</title>
                            <style>
                                @page {
                                    margin-top: 0mm;
                                    margin-bottom: 0mm;
                                    size: 70mm 50mm;
                                }
                                body { font-family: Arial, "微軟正黑體", "Microsoft JhengHei", sans-serif; margin: 0; font-size: 8pt; }
                                .print-container { width: 70mm; height: 50mm; border: none; padding: 2mm; box-sizing: border-box; overflow: hidden; page-break-after: always; }
                                .part-number-row { font-size: 12pt; font-weight: bold; text-align: left; margin-bottom: 0.5mm; padding-bottom: 0mm; }
                                .content-table { width: 100%; border-collapse: collapse; }
                                .content-table td { padding: 1mm; vertical-align: top; }
                                .left-col { text-align: left; font-size: 10pt; }
                                .right-col { text-align: left; vertical-align: top; margin: 0; }
                                .label { font-weight: bold; font-size: 9pt; }
                                .info-line { line-height: 1.5; font-size: 9pt; }
                                .info-line:not(:last-child) { margin-bottom: 0.5mm; }
                                .company-footer { margin-top: 0mm; padding-top: 1mm; font-size: 11pt; font-weight: bold; text-align: justify; text-justify: inter-word; border-top: 1px solid black; }
                                .qr-code-image { max-width: 90%; height: auto; display: block; margin: 0; }
                            </style>
                        </head>
                        <body>${allPagesHtml}</body></html>`);
                    printWindow.document.close();
                    printWindow.focus();
                    // It's generally better to let the user initiate print from the browser's print dialog
                    // but if auto-print is desired and works in your target browsers:
                    setTimeout(() => {
                        printWindow.print();
                    }, 500);
                }
            });

            // --- QR Code Modal: 箱數 input Enter key press to trigger Generate QR Code button ---
            $('#modals-container').on('keypress', '.qty-per-unit', function(e) {
                if (e.which === 13) { // Enter key pressed
                    e.preventDefault(); // Prevent default form submission or other newline behavior
                    const $modal = $(this).closest('.modal');
                    const $printButton = $modal.find('.direct-print-qrcode-button');
                    const qtyPerUnitVal = $(this).val();

                    if (qtyPerUnitVal && parseFloat(qtyPerUnitVal) > 0 && $printButton.is(':visible')) {
                        $printButton.click();
                    }
                }
            });

            // --- Auto-focus on "箱數" input when QR Code modal is shown ---
            $('#modals-container').on('shown.bs.modal', '.modal[id^="myModal_qrcode_"]', function() {
                // Find the 'qty-per-unit' input within this specific modal and focus on it
                var $qtyInput = $(this).find('.qty-per-unit');
                if ($qtyInput.length) {
                    $qtyInput.focus();
                }
            });

            // --- Add/Remove Abnormal Entry Rows ---
            $(document).on('click', '.add-abnormal-row', function() {
                var $thisButton = $(this);
                var $wrapper = $thisButton.closest('[id^="abnormal-rows-wrapper_"]');
                var currentRowCount = $wrapper.find('.abnormal-entry-row').length;
                var $currentRow = $thisButton.closest('.abnormal-entry-row'); // Get the row containing the clicked button
                if (currentRowCount >= 15) {
                    alert("最多只能新增 15 筆異常紀錄。");
                    return; // Stop adding rows
                }

                var $newRow = $currentRow.clone(true, true); // Clone the current row (the one with the plus button)

                // Clear input values in the new row
                $newRow.find('input[type="number"], textarea').val('');
                $newRow.find('input[name="qc_check_id[]"]').val(''); // Clear hidden qc_check_id
                $newRow.find('.abnormal-check-date').empty(); // Clear the date display

                // Change the plus button to a minus button in the new row
                var $actionButtonsDiv = $newRow.find('.abnormal-action-buttons');
                $actionButtonsDiv.empty(); // Clear existing buttons
                $actionButtonsDiv.append('<button type="button" class="btn btn-danger btn-xs remove-abnormal-row"><i class="fa fa-minus"></i></button>');

                // Append the new row to the end of the wrapper
                $wrapper.append($newRow);
            });

            $(document).on('click', '.remove-abnormal-row', function() {
                var $thisButton = $(this);
                var $currentRow = $thisButton.closest('.abnormal-entry-row');
                var $wrapper = $currentRow.closest('[id^="abnormal-rows-wrapper_"]');
                var wasFirstRow = $currentRow.is(':first-child');
                $currentRow.remove();

                var $remainingRows = $wrapper.find('.abnormal-entry-row');
                if ($remainingRows.length === 0) {
                    // If all rows are removed, add a new blank row with only a '+' button
                    var bomIngFidForBlank = $wrapper.attr('id').replace('abnormal-rows-wrapper_', ''); // Corrected ID replacement
                    var sqtyForBlank = parseFloat($('#myModal_ok_' + bomIngFidForBlank).find('form').data('sqty')) || 0;
                    var blankRowHtml = `
                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                            <div style="flex: 0 0 100px; margin-right: 10px;">
                                <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${sqtyForBlank}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                <input type="hidden" name="qc_check_id[]" value="">
                            </div>
                            <div style="flex: 1; margin-right: 10px;">
                                <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註"></textarea>
                            </div>
                            <div style="flex: 0 0 80px; margin-right: 10px; padding-top: 7px;" class="ok-check-date"></div>
                            <div style="flex: 0 0 80px; text-align: left;" class="ok-action-buttons">
                                <button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                            </div><!-- Only plus button for the single blank row -->
                        </div>`;
                    $wrapper.append(blankRowHtml);
                } else if (wasFirstRow) {
                    // The new first row needs both + and -
                    var $newFirstRow = $remainingRows.first();
                    var $actionButtonsDiv = $newFirstRow.find('.abnormal-action-buttons');
                    $actionButtonsDiv.html(
                        '<button type="button" class="btn btn-warning btn-xs add-abnormal-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                        '<button type="button" class="btn btn-danger btn-xs remove-abnormal-row" title="刪除此筆"><i class="fa fa-minus"></i></button>');

                }
            });

            // ── QQ Modal：即時偵測「開異常單」按鈕顯示 ──────────
            // 條件：同一行的「異常數」和「異常原因」都有值才顯示
            $(document).on('input', '.abnormal-qty-input, .abnormal-remark-input', function() {
                var $row   = $(this).closest('.abnormal-entry-row');
                var qty    = $row.find('.abnormal-qty-input').val().trim();
                var remark = $row.find('.abnormal-remark-input').val().trim();
                var $btn   = $row.find('.btn-open-ncr');
                if (qty && remark) {
                    if (!$btn.length) {
                        var bomIngFid = $row.closest('[id^="myModal_qq_"]').attr('id').replace('myModal_qq_', '');
                        $row.find('.abnormal-action-buttons').append(
                            '<button type="button" class="btn-open-ncr" title="儲存後開立品質異常單" data-fid="' + bomIngFid + '">' +
                            '<i class="fa fa-file-text-o"></i> 開異常單</button>'
                        );
                    }
                } else {
                    var qcCheckId = $row.find('input[name="qc_check_id[]"]').val();
                    if (!qcCheckId) $btn.remove();
                }
            });

            // 點擊「開異常單」— 先自動儲存 QQ，成功後開 NCR modal，NCR 關閉後回 QQ modal
            $(document).on('click', '.btn-open-ncr', function() {
                var $btn      = $(this);
                var $row      = $btn.closest('.abnormal-entry-row');
                var $qqModal  = $btn.closest('.modal');
                var qcCheckId = $row.find('input[name="qc_check_id[]"]').val();
                var bomIngFid = $btn.data('fid') ||
                    $qqModal.attr('id').replace('myModal_qq_', '');

                // 若已有 qc_check_id，直接開異常單
                if (qcCheckId) {
                    openNcrModal(qcCheckId, bomIngFid, $qqModal);
                    return;
                }

                // 先自動儲存 QQ，再開異常單
                var $form = $qqModal.find('form');
                if (!$form.length) { alert('找不到表單'); return; }

                // 驗證此行
                var qty    = $row.find('.abnormal-qty-input').val().trim();
                var remark = $row.find('.abnormal-remark-input').val().trim();
                if (!qty || !remark) { alert('請填寫異常數和異常原因後再開立'); return; }

                $btn.prop('disabled', true).text('儲存中...');

                $.ajax({
                    url: $form.attr('action'),
                    type: 'POST',
                    data: $form.serialize(),
                    dataType: 'json',
                    success: function(res) {
                        $btn.prop('disabled', false).html('<i class="fa fa-file-text-o"></i> 開異常單');
                        if (!res.success) {
                            alert('儲存失敗：' + (res.message || ''));
                            return;
                        }
                        // 更新 qc_check_id（從 response 找到這行的 id）
                        var entries = res.individual_qc_entries || [];
                        // 比對備註找到對應行
                        var matchEntry = null;
                        entries.forEach(function(e) {
                            if (e.QC_check === 'QQ' && e.QC_ps === remark) matchEntry = e;
                        });
                        if (matchEntry) {
                            $row.find('input[name="qc_check_id[]"]').val(matchEntry.qc_check_id);
                            qcCheckId = matchEntry.qc_check_id;
                        }
                        // 開異常單
                        openNcrModal(qcCheckId, bomIngFid, $qqModal);
                    },
                    error: function() {
                        $btn.prop('disabled', false).html('<i class="fa fa-file-text-o"></i> 開異常單');
                        alert('儲存請求失敗');
                    }
                });
            });

            // 開 NCR modal，NCR 關閉後回 QQ modal
            function openNcrModal(qcCheckId, bomIngFid, $qqModal) {
                if (!qcCheckId) { alert('請先儲存異常紀錄'); return; }
                $('#qca_bom_ing_fid').val(bomIngFid);
                $('#qca_qc_check_id').val(qcCheckId);
                $('#qca_edit_id').val('');
                $('#qcaModalTitle').text('開立品質異常單');
                $('#qca_save_btn').attr('onclick', 'saveQCQA()').text('確認開立');
                resetQCAForm();
                $.post(QA_API, { action: 'get_next_no' }, function(r) {
                    if (r.success) $('#qca_no').val(r.no);
                }, 'json');
                $('#qca_found_unit').val('廠內');
                $('#qca_occurrence_date').val(new Date().toISOString().split('T')[0]);
                loadQCATypes();
                loadQCADeptContainer({});

                // NCR 關閉後回到 QQ modal
                $('#qcaModal').one('hidden.bs.modal', function() {
                    if ($qqModal && $qqModal.length) {
                        $qqModal.modal('show');
                    }
                });
                $('#qcaModal').modal('show');
            }
            $(document).on('click', '.add-ok-row', function() {
                var $thisButton = $(this);
                var $wrapper = $thisButton.closest('[id^="ok-rows-wrapper_"]');
                var currentRowCount = $wrapper.find('.ok-entry-row').length;

                var $currentRow = $thisButton.closest('.ok-entry-row');
                var $newRow = $currentRow.clone(true, true); // Clone the current row

                // Clear input values in the new row
                $newRow.find('input[type="number"].ok-qty-input').val(''); // Clear quantity
                $newRow.find('textarea.ok-remark-input').val(''); // Clear remark
                $newRow.find('input[name="qc_check_id[]"]').val(''); // Clear hidden qc_check_id if it exists
                $newRow.find('.ok-check-date').empty(); // Clear the date display for the new row


                // Change the plus button to a minus button in the new row
                var $actionButtonsDiv = $newRow.find('.ok-action-buttons');
                $actionButtonsDiv.empty(); // Clear existing buttons
                $actionButtonsDiv.append('<button type="button" class="btn btn-danger btn-xs remove-ok-row"><i class="fa fa-minus"></i></button>'); // Use btn-danger for remove

                // Append the new row
                $wrapper.append($newRow);
            });

            $(document).on('click', '.remove-ok-row', function() {
                var $thisButton = $(this);
                var $currentRow = $thisButton.closest('.ok-entry-row');
                var $wrapper = $currentRow.closest('[id^="ok-rows-wrapper_"]');

                var wasFirstRow = $currentRow.is(':first-child');

                $currentRow.remove();

                var $remainingRows = $wrapper.find('.ok-entry-row');
                if ($remainingRows.length === 0) {
                    // If all rows are removed, add a new blank row with both + and -
                    var bomIngFidForBlank = $wrapper.attr('id').replace('ok-rows-wrapper_', ''); // Corrected ID replacement
                    var sqtyForBlank = parseFloat($('#myModal_ok_' + bomIngFidForBlank).find('form').data('sqty')) || 0;
                    var blankRowHtml = `
                        <div class="ok-entry-row" style="display: flex; align-items: center; margin-bottom: 10px;">
                            <div style="width: 100px; margin-right: 10px; flex-shrink: 0;">
                                <input type="number" class="form-control ok-qty-input" name="ok_total_qty[]" value="${sqtyForBlank}" style="width: 90px;" min="0" max="99999" oninput="if(this.value.length > 5) this.value = this.value.slice(0,5);" placeholder="數量">
                                <input type="hidden" name="qc_check_id[]" value="">
                            </div>
                            <div style="flex: 1; margin-right: 10px;">
                                <textarea rows="1" class="form-control ok-remark-input" style="width: 100%;" name="QCmessage[]" placeholder="允收備註"></textarea>
                            </div>
                            <div style="width: 80px; margin-right: 10px; flex-shrink: 0; padding-top: 7px;" class="ok-check-date"></div>
                            <div style="width: 80px; flex-shrink: 0; text-align: left;" class="ok-action-buttons">
                                <button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button>
                                <button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button><!-- Both buttons for the single blank row -->
                            </div>
                        </div>`;
                    $wrapper.append(blankRowHtml);
                } else if (wasFirstRow) {
                    var $newFirstRow = $remainingRows.first();
                    var $actionButtonsDiv = $newFirstRow.find('.ok-action-buttons');
                    $actionButtonsDiv.html(
                        '<button type="button" class="btn btn-warning btn-xs add-ok-row" title="新增一筆"><i class="fa fa-plus"></i></button> ' +
                        '<button type="button" class="btn btn-danger btn-xs remove-ok-row" title="刪除此筆"><i class="fa fa-minus"></i></button>');

                }
            });

            // --- New Handler for QQ Modal's "清除並儲存" button ---
            $('#modals-container').on('click', '.clear-all-qq-entries-btn', function() {
                var $button = $(this);
                var $form = $button.closest('.modal-content').find('form');
                var actionUrl = $form.attr('action');
                var fullUrl = new URL(actionUrl, window.location.href);
                var bomIngId = fullUrl.searchParams.get("bi");
                var originalButtonText = $button.text();

                $button.prop('disabled', true).text('處理中...');

                $.ajax({
                    url: actionUrl, // This should be _updateQC_check_list_QQ.php
                    type: 'POST',
                    data: {
                        clear_remark_only: "1"
                    }, // Key flag for backend
                    dataType: 'json',
                    success: function(writeResponse) {
                        if (writeResponse.success) {
                            // Fetch full details for UI update
                            $.ajax({
                                url: '../../src/store/_fetch_qc_row_details.php',
                                type: 'GET',
                                data: {
                                    bi: bomIngId
                                },
                                dataType: 'json',
                                success: function(fetchResponse) {
                                    if (fetchResponse.success && fetchResponse.data) {
                                        var $targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                                        updateTableRowDOM($targetRow, fetchResponse.data);

                                        var itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                        if (itemIndex > -1) {
                                            // Simplified update for allRawData; assumes fetchResponse.data structure matches needs
                                            Object.assign(window.allRawData[itemIndex], fetchResponse.data.bom_ing_details, {
                                                individual_qc_entries: fetchResponse.data.individual_qc_entries,
                                                total_qq_qty: fetchResponse.data.total_qq_qty,
                                                total_ok_qty: fetchResponse.data.total_ok_qty,
                                                latest_QQ_date_formatted: fetchResponse.data.latest_QQ_date_formatted,
                                                latest_ok_date_formatted: fetchResponse.data.latest_ok_date_formatted
                                            });
                                        }
                                        $form.closest('.modal').modal('hide');
                                        showTemporaryMessage(writeResponse.message || '異常紀錄已清除', 'success');
                                    } else {
                                        showTemporaryMessage('獲取更新數據失敗: ' + (fetchResponse.message || '未知錯誤'), 'error');
                                    }
                                },
                                error: function(jqXHR, textStatus, errorThrown) {
                                    showTemporaryMessage('獲取更新數據請求失敗: ' + textStatus, 'error');
                                }
                            });
                        } else {
                            showTemporaryMessage('清除失敗: ' + (writeResponse.message || '未知錯誤'), 'error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });


            // --- Modified Handler for OK Modal's "清除並儲存" button ---
            $(document).on('click', '.clear-and-save-ok-btn', function() {
                var $button = $(this);
                var $modalContent = $button.closest('.modal-content');
                var $form = $modalContent.find('form');
                var originalButtonText = $button.text();

                // 清除所有相關的輸入欄位，作為操作的即時 UI 反饋。
                // 後端會處理實際的資料庫清除。
                $form.find('input.ok-qty-input').val('');
                $form.find('textarea.ok-remark-input').val('');
                $form.find('select[name="container[]"]').val(''); // ⭐ 重設容器下拉選單
                $form.find('input[name="quantity[]"]').val(''); // ⭐ 清除箱數輸入框

                $button.prop('disabled', true).text('處理中...');

                var formData = $form.serializeArray();
                formData.push({
                    name: "clear_remark_only",
                    value: "1"
                }); // Add flag

                $.ajax({
                    url: $form.attr('action'), // Action from the form attribute
                    type: 'POST',
                    data: $.param(formData),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var actionUrl = $form.attr('action'); // Original action URL from the form
                            var fullUrl = new URL(actionUrl, window.location.href);
                            var bomIngId = fullUrl.searchParams.get("bi");

                            // Fetch full details for UI update
                            $.ajax({
                                url: '../../src/store/_fetch_qc_row_details.php',
                                type: 'GET',
                                data: {
                                    bi: bomIngId
                                },
                                dataType: 'json',
                                success: function(fetchResponse) {
                                    if (fetchResponse.success && fetchResponse.data) {
                                        var $targetRow = $('tr[data-bom-ing-fid="' + bomIngId + '"]');
                                        updateTableRowDOM($targetRow, fetchResponse.data);

                                        var itemIndex = window.allRawData.findIndex(item => item.bom_ing_fid === bomIngId);
                                        if (itemIndex > -1) {
                                            Object.assign(window.allRawData[itemIndex], fetchResponse.data.bom_ing_details, {
                                                individual_qc_entries: fetchResponse.data.individual_qc_entries,
                                                total_qq_qty: fetchResponse.data.total_qq_qty,
                                                total_ok_qty: fetchResponse.data.total_ok_qty,
                                                latest_QQ_date_formatted: fetchResponse.data.latest_QQ_date_formatted,
                                                latest_ok_date_formatted: fetchResponse.data.latest_ok_date_formatted
                                            });
                                        }
                                        $form.closest('.modal').modal('hide');
                                        showTemporaryMessage(response.message || '允收紀錄已清除', 'success'); // Use message from original POST
                                    } else {
                                        showTemporaryMessage('獲取更新數據失敗: ' + (fetchResponse.message || '未知錯誤'), 'error');
                                    }
                                },
                                error: function(jqXHR, textStatus, errorThrown) {
                                    showTemporaryMessage('獲取更新數據請求失敗: ' + textStatus, 'error');
                                }
                            });
                        } else {
                            showTemporaryMessage('清除失敗: ' + (response.message || '未知錯誤'), 'error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        showTemporaryMessage('請求失敗: ' + textStatus, 'error');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalButtonText);
                    }
                });
            });

            // --- Auto-update Logic ---
            // 舊的固定頻率輪詢由頁面底部的 30 秒輪詢取代，此處不再啟動
            // autoUpdateIntervalId = setInterval(fetchAndUpdateData, AUTO_UPDATE_INTERVAL_MS);
            console.log('[QC] DataTable ready，啟動首次資料載入');

            // Pause auto-update when a modal is shown
            $(document).on('show.bs.modal', '.modal', function() {
                console.log('[Auto-Update] 偵測到 Modal 開啟，暫停自動更新。');
                autoUpdatePaused = true;
            });
            // --- 新增(未在列表上) Modal 相關邏輯 ---

            // 開啟 modal 前清除舊資料
            $(document).on('click', '#btn-add-custom', function() {
                var $modal = $('#myModal_reply_custom');
                $modal.find('#input-bom-query').val('');
                $modal.find('[name=clientName], [name=dId], [name=sqty], [name=ps]').val('');
                $modal.find('#select-bom-ing').html('<option value="">請先更新BOM</option>');
                $('#btn-custom-qrcode').hide();
                customBomData = null; // 清除暫存資料
            });

            // 更新 BOM 資料
            $(document).on('click', '#btn-bom-update', function() {
                const bom = $('#input-bom-query').val().trim();
                if (!/^B-\d{10}$/.test(bom)) {
                    alert('請輸入格式正確的 BOM：B-後接10位數字');
                    return;
                }
                var $btn = $(this);
                $btn.prop('disabled', true).text('查詢中...');

                $.ajax({
                    url: '../../src/store/_get_bom_data.php',
                    method: 'POST',
                    data: { bom: bom },
                    dataType: 'json'
                }).done(function(data) {
                    $btn.prop('disabled', false).text('更新');
                    if (!data.success) {
                        customBomData = null;
                        alert(data.message || '查無資料');
                        return;
                    }
                    customBomData = data;

                    var $modal = $('#myModal_reply_custom');
                    $modal.find('[name=clientName]').val(data.Client_Name);
                    $modal.find('[name=dId]').val(data.d_id);

                    const $sel = $modal.find('#select-bom-ing').empty();
                    if (data.processes && data.processes.length > 0) {
                        const getVisualLength = (str) => {
                            if (!str) return 0;
                            return str.replace(/[^\x00-\xff]/g, "aa").length;
                        };
                        let maxProcessLength = 0;
                        data.processes.forEach(item => {
                            const processPart = `[${item.bom_sn}] ${item.process_no} ${item.ProcessName}`;
                            const len = getVisualLength(processPart);
                            if (len > maxProcessLength) maxProcessLength = len;
                        });
                        data.processes.forEach(function(item) {
                            const processPart = `[${item.bom_sn}] ${item.process_no} ${item.ProcessName}`;
                            const currentLength = getVisualLength(processPart);
                            const paddingCount = maxProcessLength > currentLength ? maxProcessLength - currentLength : 0;
                            const padding = '\u00a0'.repeat(paddingCount);
                            const makerDisplay = (item.maker_id && String(item.maker_id).trim() !== '')
                                ? item.maker_id
                                : (item.maker_id_no || '');
                            let displayText = processPart + padding;
                            if (makerDisplay) displayText += '\u3000' + makerDisplay;
                            $sel.append($('<option>').val(item.bom_ing_fid).text(displayText));
                        });
                        // 顯示操作列
                        $modal.find('.custom-modal-options').show();
                        $sel.trigger('change');
                    } else {
                        $sel.append('<option value="">此BOM無製程資料</option>');
                        $modal.find('.custom-modal-options').hide();
                        $sel.trigger('change');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('更新');
                    customBomData = null;
                    alert('伺服器錯誤，無法取得BOM資料。');
                });
            });

            // 製程有選取才顯示 QR Code 按鈕
            $(document).on('change', '#select-bom-ing', function() {
                $('#btn-custom-qrcode').toggle(!!$(this).val());
            });

            // 新增報工 Modal 的 QR Code 按鈕（與列表上的 QR Code 同功能）
            $(document).on('click', '#btn-custom-qrcode', function() {
                var selectedFid = $('#select-bom-ing').val();
                if (!selectedFid) { alert('請先選擇製程'); return; }

                var existing = (window.allRawData || []).find(function(d) {
                    return String(d.bom_ing_fid) === String(selectedFid);
                });
                if (existing) {
                    $('#myModal_reply_custom').modal('hide');
                    openQCModal('qrcode', selectedFid);
                    return;
                }
                if (!customBomData || !customBomData.success) {
                    alert('無法建立彈窗，請先點擊「更新」');
                    return;
                }
                var selectedProcess = customBomData.processes.find(function(p) {
                    return String(p.bom_ing_fid) === String(selectedFid);
                });
                if (!selectedProcess) { alert('找不到所選製程的資料'); return; }

                var targetModalId = '#myModal_qrcode_' + selectedFid;
                if (!$(targetModalId).length) {
                    $('#modals-container').append(generateModalsForItem(selectedProcess));
                }
                $('#myModal_reply_custom').modal('hide');
                $(targetModalId).modal('show');
            });

            // 在 myModal_reply_custom 中，異常/允收 按鈕觸發 lazy modal
            $(document).on('click', '#myModal_reply_custom .btn-option-abnormal, #myModal_reply_custom .btn-option-accept', function() {
                var type = $(this).hasClass('btn-option-abnormal') ? 'qq' : 'ok';
                var selectedFid = $('#select-bom-ing').val();

                if (!selectedFid) {
                    alert('請先選擇製程');
                    return;
                }

                // 確保 allRawData 有這筆（若已在列表上）
                var existing = (window.allRawData || []).find(function(d) {
                    return String(d.bom_ing_fid) === String(selectedFid);
                });

                if (existing) {
                    // 已在列表上：直接用 lazy modal
                    $('#myModal_reply_custom').modal('hide');
                    openQCModal(type, selectedFid);
                    return;
                }

                // 不在列表上：從 customBomData 建立臨時 item
                if (!customBomData || !customBomData.success) {
                    alert('無法建立彈窗，請先點擊「更新」');
                    return;
                }

                var selectedProcess = customBomData.processes.find(function(p) {
                    return String(p.bom_ing_fid) === String(selectedFid);
                });
                if (!selectedProcess) {
                    alert('找不到所選製程的資料');
                    return;
                }

                var targetModalId = '#myModal_' + type + '_' + selectedFid;
                if (!$(targetModalId).length) {
                    var html = generateModalsForItem(selectedProcess);
                    $('#modals-container').append(html);
                    $(".qc-modal-reply-datepicker").datepicker({ changeMonth: true, changeYear: true, showMonthAfterYear: true });
                }
                $('#myModal_reply_custom').modal('hide');
                $(targetModalId).modal('show');
            });

            // ⭐ 新增：當「完成」Modal 顯示時，動態計算並填入內容
            $(document).on('show.bs.modal', '[id^="myModal_complete_"]', function() {
                var modal = $(this);
                var bomIngFid = modal.data('bom-ing-fid');
                var modalBody = modal.find('.modal-body');

                // 從 allRawData 中找到對應的資料
                var itemData = allRawData.find(item => String(item.bom_ing_fid) === String(bomIngFid));

                if (!itemData) {
                    modalBody.html('<p class="text-danger">找不到資料。</p>');
                    return;
                }

                // 獲取數量
                var totalQty = parseFloat(itemData.sqty) || 0;
                var abnormalQty = parseFloat(itemData.QC_QQ_sqty) || 0;
                var acceptedQty = parseFloat(itemData.QC_ok_sqty) || 0;
                var shortage = totalQty - abnormalQty - acceptedQty;

                // 建立要顯示的 HTML 字串
                var contentHtml = `總數 ${totalQty}`;

                if (abnormalQty > 0) {
                    contentHtml += ` - <span style="color: #f0ad4e; font-weight: bold;">異常 x${abnormalQty}</span>`;
                }

                if (acceptedQty > 0) {
                    contentHtml += ` - <span style="color: green; font-weight: bold;">允收 x${acceptedQty}</span>`;
                }

                contentHtml += ' = ';

                if (shortage <= 0) {
                    contentHtml += '<span style="color: green; font-weight: bold;">已全部檢驗</span>';
                } else {
                    contentHtml += `<span style="color: red; font-weight: bold;">短缺 x${shortage}</span>`;
                }

                modalBody.html(`<p>${contentHtml}</p>`);
            });

            // ⭐ 新增：處理「確認完成」按鈕點擊事件
            $(document).on('click', '.btn-confirm-completion', function() {
                var $button = $(this);
                var modal = $button.closest('.modal');
                var bomIngFid = modal.data('bom-ing-fid');

                if (!confirm('您確定要完成此筆檢驗嗎？此操作將更新狀態並從清單中移除。')) {
                    return;
                }

                $button.prop('disabled', true).text('處理中...');

                $.ajax({
                    url: '../../src/store/_update_qc_completion.php',
                    type: 'POST',
                    data: {
                        bom_ing_fid: bomIngFid
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            modal.modal('hide');
                            modal.one('hidden.bs.modal', function () {
                                // 從 allRawData 移除
                                window.allRawData = (window.allRawData || []).filter(function(item) {
                                    return String(item.bom_ing_fid) !== String(bomIngFid);
                                });
                                // 直接從 DataTable 移除該行，不重新渲染整張表
                                if (dataTableInstance) {
                                    dataTableInstance.rows(function(idx, data) {
                                        return String(data[0]) === String(bomIngFid);
                                    }).remove().draw(false);
                                }
                                // 移除已建立的 lazy modal DOM
                                $('[id^="myModal_"][id$="_' + bomIngFid + '"]').remove();
                                // 更新 count badge
                                qcPagination.totalRecords = Math.max(0, qcPagination.totalRecords - 1);
                                $('#qc-count-badge').text('共 ' + qcPagination.totalRecords + ' 筆');
                            });
                        } else {
                            alert('更新失敗: ' + response.message);
                            $button.prop('disabled', false).text('確認完成');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('請求失敗: ' + textStatus);
                        $button.prop('disabled', false).text('確認完成');
                        console.error("Error on completion:", textStatus, errorThrown);
                    }
                });
            });

            // ⭐ 新增：使用事件委派處理所有（包括動態新增的）允收/異常等 modal 表單提交
            // 這個處理程序會監聽整個頁面，捕捉所有符合 'form[action*="_updateQC_check_list_"]' 選擇器的表單提交事件
            // ── 全域委派 submit 事件，攔截所有動態/靜態的 _updateQC_check_list_ 表單
            $(document).on('submit', 'form[action*="_updateQC_check_list_"]', function(e) {
                e.preventDefault(); // 阻止預設跳頁
                var $form = $(this);

                $.ajax({
                        url: $form.attr('action'),
                        type: 'POST',
                        data: $form.serialize(),
                        dataType: 'json'
                    })
                    .done(function(res) {
                        // TODO：把這行換成你自己的更新表格函式
                        updateTableRowDOM(res.data);
                        // 關閉 modal
                        $form.closest('.modal').modal('hide');
                    })
                    .fail(function(xhr) {
                        alert('儲存失敗：' + xhr.responseText);
                    });
            });

        });
    </script>

    <!-- 更新後的腳本，用於防止 "QC設定(QC)" 選單在特定頁面自動展開 -->
    <script>
        $(document).ready(function() {
            // 稍微增加延遲，確保 custom.min.js 中的 init_sidebar() 已完全執行
            setTimeout(function() {
                var currentPath = window.location.pathname;
                var pageSpecificLogic = false;

                // 檢查目前頁面是否為 QC_check_list.php
                if (currentPath.endsWith('/QC_check_list.php') || currentPath.endsWith('/QC_check_list2.php')) {
                    pageSpecificLogic = true;
                }

                if (pageSpecificLogic) {
                    // 尋找 "QC設定(QC)" 的父層 <li> 元素
                    var $qcSettingsLi = $('#sidebar-menu .nav.side-menu > li').filter(function() {
                        var linkText = $(this).children('a').clone().children().remove().end().text().trim();
                        return linkText.includes("QC設定(QC)");
                    });

                    if ($qcSettingsLi.length > 0) {
                        // 移除 active 狀態 class
                        $qcSettingsLi.removeClass('active active-sm');

                        // 明確收起其子選單 (ul.child_menu)
                        var $childMenu = $qcSettingsLi.children('ul.child_menu');
                        if ($childMenu.length > 0 && $childMenu.is(':visible')) {
                            $childMenu.slideUp(0); // 立即收起
                        }
                    }
                }
            }, 200); // 將延遲增加到 200 毫秒，可以根據需要微調
        });

        // 顯示允收內容器與箱數資料
        $(document).on('show.bs.modal', '[id^="myModal_ok_"]', function(e) {
            var modal = $(this);
            var bomIngFid = modal.attr('id').replace('myModal_ok_', '');
            $.getJSON('../../src/store/_get_qcps.php', {
                bi: bomIngFid
            }, function(data) {
                fillOkContainerRow(modal.find('.form-group').eq(0), data.QC_ps);
                fillOkContainerRow(modal.find('.form-group').eq(1), data.QC_ps2);
            });
        });

        function fillOkContainerRow($row, val) {
            if (!val) return;
            // 解析「數量+縮寫」格式，如 "1P"
            var match = val.match(/^(\d+)([\u4e00-\u9fa5A-Za-z]+)/);
            if (!match) return;
            var qtyVal = match[1];
            var abbr = match[2];
            // 直接設定縮寫作為 select value
            $row.find('select[name="container[]"]').val(abbr);
            $row.find('input[name="quantity[]"]').val(qtyVal);
        }


        // 每 30 秒輪詢當前頁資料（不重置頁碼）
        setInterval(function() {
            if (!autoUpdatePaused && !isAnyModalOpen()) {
                fetchAndUpdateData(qcPagination.page);
            }
        }, 30000);
    </script>
    <!-- === 請貼入 QC_check_list copy(自動更新改一半).php 頁面底部 (放於 </body> 前) === -->

    <!-- 1. 觸發按鈕示例：於表格列或新增按鈕處加入 data-action 與必要參數 -->


    <!-- 2. AJAX 事件委派及 Modal 載入流程 -->
    <script>
        $(function() {
            // 攔截所有 動態新增的 QC 表單 提交，並以 AJAX 送出
            $(document).on('submit', '.qc-modal-form', function(e) {
                e.preventDefault();
                var $form = $(this);
                var actionUrl = $form.attr('action');
                var formData = $form.serialize();

                $.post(actionUrl, formData, function(response) {
                    if (response.success) {
                        // 依照回傳更新對應 table row
                        updateTableRow(response.individual_qc_entries || response.data);
                        // 關閉 Modal
                        $form.closest('.modal').modal('hide');
                    } else {
                        alert(response.message || 'QC 更新失敗');
                    }
                }, 'json').fail(function() {
                    alert('伺服器錯誤，請稍後再試');
                });
            });

            // 點擊按鈕打開 QC Modal 並載入對應後端
            $(document).on('click', '[data-action="open-qc-modal"]', function() {
                var type = $(this).data('type'); // ok 或 ng
                var bi = $(this).data('bi'); // bom_ing_fid
                var uid = $(this).data('uid'); // 使用者 ID
                var url = '<?= $basePath ?>/src/store/_updateQC_check_list_' + type + '.php?bi=' + bi + '&id=' + uid;

                // 顯示載入中指示，並動態替換內容
                $('#qcModal .modal-content').html(
                    '<div class="modal-body text-center p-4">' +
                    '  <div class="spinner-border" role="status"><span class="sr-only">載入中...</span></div>' +
                    '</div>'
                ).load(url, function(response, status) {
                    if (status === 'error') {
                        $('#qcModal .modal-content').html(
                            '<div class="modal-body text-danger p-3">載入失敗，請重試</div>'
                        );
                    } else {
                        // 為動態載入的 <form> 標籤加上 class
                        var $form = $(this).find('form');
                        $form.addClass('qc-modal-form');
                        // 將所有 <button> 設為 submit
                        $form.find('button').attr('type', 'submit');
                    }
                    $('#qcModal').modal('show');
                });
            });
        });

        // 更新表格列輔助函式 (需依照實際欄位做修改)
        function updateTableRow(data) {
            // data 裡通常帶回 qc_check_id, processing_state, QC 數量等
            var $row = $('#row-' + data.qc_check_id);
            if (!$row.length) return;
            $row.find('.qc-status').text(data.processing_state);
            $row.find('.qc-qty').text(data.total_ok_qty || data.total_qq_qty || '');
            // ... 更多欄位更新 ...
        }
    </script>

    <!-- 3. 全站共用 QC Modal 結構 (放於 </body> 前) -->
    <div id="qcModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <!-- 初始內容：載入中指示器 -->
                <div class="modal-body text-center p-4">
                    <div class="spinner-border" role="status">
                        <span class="sr-only">載入中...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── QC完工紀錄 Drawer ── -->
    <div class="qc-drawer-overlay" id="qcCompletedOverlay" onclick="closeCompletedDrawer()"></div>
    <div class="qc-drawer" id="qcCompletedDrawer">
        <div class="qc-drawer-header">
            <h5><i class="fa fa-check-circle"></i> QC完工紀錄</h5>
            <button class="qc-drawer-close" onclick="closeCompletedDrawer()">×</button>
        </div>
        <!-- 篩選列 -->
        <div style="padding:10px 16px;border-bottom:1px solid #E4E9F0;display:flex;gap:8px;align-items:center;flex-shrink:0;">
            <input type="text" id="cc-search-input" placeholder="搜尋 BOM / 料號 / 客戶 / 廠商..."
                   style="flex:1;border:1px solid #ddd;border-radius:4px;padding:5px 10px;font-size:13px;">
            <button onclick="ccSearch()" style="padding:5px 12px;background:#2E6DA4;color:#fff;border:none;border-radius:4px;font-size:13px;cursor:pointer;">搜尋</button>
            <button onclick="ccReset()" style="padding:5px 10px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;font-size:13px;cursor:pointer;">重設</button>
        </div>
        <div class="qc-drawer-body" id="qcCompletedBody">
            <div class="text-center text-muted" style="padding:40px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
        </div>
        <!-- 分頁列 -->
        <div id="cc-pagination" style="padding:10px 16px;border-top:1px solid #E4E9F0;display:flex;align-items:center;justify-content:center;gap:4px;flex-shrink:0;flex-wrap:wrap;"></div>
    </div>

    <script>
    // ── QC完工紀錄 Drawer ─────────────────────────────────────
    var ccState = { page:1, perPage:5, search:'', totalPages:1, totalRecords:0, loading:false };

    function openCompletedDrawer() {
        document.getElementById('qcCompletedOverlay').style.display = 'block';
        document.getElementById('qcCompletedDrawer').classList.add('open');
        if (ccState.page === 1 && !ccState._loaded) {
            ccState._loaded = false;
            loadCompletedRecords(1, '');
        }
    }
    function closeCompletedDrawer() {
        document.getElementById('qcCompletedOverlay').style.display = 'none';
        document.getElementById('qcCompletedDrawer').classList.remove('open');
    }
    function ccSearch() {
        ccState.search = document.getElementById('cc-search-input').value.trim();
        loadCompletedRecords(1, ccState.search);
    }
    function ccReset() {
        document.getElementById('cc-search-input').value = '';
        ccState.search = '';
        loadCompletedRecords(1, '');
    }
    function ccGoPage(p) {
        if (p < 1 || p > ccState.totalPages || p === ccState.page) return;
        loadCompletedRecords(p, ccState.search);
    }
    // 搜尋欄按 Enter
    document.addEventListener('DOMContentLoaded', function() {
        var inp = document.getElementById('cc-search-input');
        if (inp) inp.addEventListener('keydown', function(e){ if(e.key==='Enter') ccSearch(); });
    });

    // 安全 escape（不依賴 ready 裡的 he()）
    function _esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function loadCompletedRecords(page, search) {
        if (ccState.loading) return;
        ccState.loading = true;
        ccState.page    = page  || 1;
        ccState.search  = (search !== undefined) ? search : ccState.search;

        var $body = $('#qcCompletedBody').html(
            '<div class="text-center text-muted" style="padding:40px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>'
        );
        $('#cc-pagination').empty();

        $.ajax({
            url: '../../src/store/_fetch_qc_data.php',
            type: 'GET',
            dataType: 'json',
            data: {
                mode:    'completed',
                page:    ccState.page,
                perPage: ccState.perPage,
                search:  ccState.search
            },
            success: function(res) {
                ccState.loading = false;
                if (!res.success) {
                    $body.html('<p class="text-danger text-center" style="padding:20px;">API錯誤：' + _esc(res.message || '') + '</p>');
                    return;
                }
                if (!res.data || !res.data.length) {
                    $body.html('<p class="text-muted text-center" style="padding:30px;">沒有完工紀錄</p>');
                    return;
                }
                ccState.totalRecords = res.totalRecords || res.data.length;
                ccState.totalPages   = res.totalPages   || 1;

                var html = '<div style="font-size:11px;color:#999;padding:0 0 8px;">共 ' + ccState.totalRecords + ' 筆</div>';
                res.data.forEach(function(r) {
                    // QC 檢驗結果
                    var qcResult = '';
                    if (+r.QC_QQ_sqty > 0) qcResult += '<span style="background:#FFF3CD;color:#856404;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:3px;">異常 x' + _esc(r.QC_QQ_sqty) + '</span>';
                    if (+r.QC_ok_sqty  > 0) qcResult += '<span style="background:#D4EDDA;color:#155724;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:3px;">允收 x' + _esc(r.QC_ok_sqty)  + '</span>';
                    if (+r.QC_ng_sqty  > 0) qcResult += '<span style="background:#F8D7DA;color:#721C24;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:3px;">驗退 x' + _esc(r.QC_ng_sqty)  + '</span>';
                    if (!qcResult) qcResult = '<span style="color:#aaa;font-size:11px;">無檢驗紀錄</span>';

                    // 品管備註
                    var psHtml = r.biqc_ps ? '<div style="font-size:11px;color:#666;margin-top:3px;"><i class="fa fa-comment-o"></i> ' + _esc(r.biqc_ps) + '</div>' : '';

                    // 異常單
                    var ncrHtml = '';
                    if (r.abnormal_order_no) {
                        var ncrStatus = r.qa_is_closed == 1 ? '已結案' : '進行中';
                        var ncrColor  = r.qa_is_closed == 1 ? '#6c757d' : '#C0392B';
                        ncrHtml = '<div style="margin-top:4px;display:flex;align-items:center;gap:5px;flex-wrap:wrap;">' +
                            '<span style="background:#FFF0F0;border:1px solid #FFCCCC;border-radius:3px;padding:1px 6px;font-size:11px;color:' + ncrColor + ';cursor:pointer;" ' +
                            'onclick="if(typeof openQCQADetailModal===\'function\')openQCQADetailModal(' + _esc(r.qa_abnormal_id) + ')">' +
                            '<i class="fa fa-file-text-o"></i> ' + _esc(r.abnormal_order_no) + '</span>' +
                            '<span style="font-size:11px;color:#888;">' + _esc(r.qa_responsible_unit || '') + '</span>' +
                            '<span style="background:' + ncrColor + ';color:#fff;border-radius:3px;padding:0 5px;font-size:10px;">' + ncrStatus + '</span>' +
                            '</div>';
                    }

                    html += '<div class="qc-completed-card">' +
                        '<div class="cc-head">' +
                            '<div style="flex:1;min-width:0;">' +
                                '<div class="cc-bom">' + _esc(r.bom) + '</div>' +
                                '<div class="cc-meta">' +
                                    _esc(r.Client_Name) + '　' + _esc(r.d_id) +
                                    (r.ProcessName ? '　' + _esc(r.ProcessName) : '') +
                                    (r.maker_id    ? '　廠商：' + _esc(r.maker_id) : '') +
                                    '　數量：' + _esc(r.sqty) +
                                '</div>' +
                                '<div style="margin-top:4px;">' + qcResult + '</div>' +
                                psHtml +
                                ncrHtml +
                            '</div>' +
                            '<div class="cc-time" style="text-align:right;flex-shrink:0;margin-left:8px;">' +
                                _esc(r.qc_completed_at) + '<br>' +
                                '<span style="color:#27AE60;font-weight:600;">' + _esc(r.qc_completed_by_name || '-') + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });
                $body.html(html);
                renderCcPagination();
            },
            error: function(xhr, status) {
                ccState.loading = false;
                $body.html('<p class="text-danger text-center" style="padding:20px;">載入失敗 HTTP ' + xhr.status + '：' + _esc(status) + '</p>');
            }
        });
    }

    function renderCcPagination() {
        var p = ccState;
        var $pg = $('#cc-pagination').empty();
        if (p.totalPages <= 1) return;
        var html = '<nav><ul class="pagination pagination-sm" style="margin:0;">';
        html += '<li' + (p.page<=1?' class="disabled"':'') + '><a href="#" onclick="ccGoPage('+(p.page-1)+');return false;">«</a></li>';
        var s=Math.max(1,p.page-2), e=Math.min(p.totalPages,p.page+2);
        if(s>1){ html+='<li><a href="#" onclick="ccGoPage(1);return false;">1</a></li>'; if(s>2) html+='<li class="disabled"><a>…</a></li>'; }
        for(var i=s;i<=e;i++) html+='<li'+(i===p.page?' class="active"':'')+'><a href="#" onclick="ccGoPage('+i+');return false;">'+i+'</a></li>';
        if(e<p.totalPages){ if(e<p.totalPages-1) html+='<li class="disabled"><a>…</a></li>'; html+='<li><a href="#" onclick="ccGoPage('+p.totalPages+');return false;">'+p.totalPages+'</a></li>'; }
        html += '<li'+(p.page>=p.totalPages?' class="disabled"':'')+'><a href="#" onclick="ccGoPage('+(p.page+1)+');return false;">»</a></li>';
        html += '</ul></nav><span style="font-size:12px;color:#999;margin-left:8px;">第'+p.page+'/'+p.totalPages+'頁</span>';
        $pg.html(html);
    }

    // ── QQ Modal：開立異常單按鈕（舊函式已整合到 ready 內的事件委派）──
    </script>

    <!-- ── QC 品質異常單開立 Modal ── -->
    <div class="modal fade" id="qcaModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header" style="background:#2A3F54;color:white;border-radius:4px 4px 0 0;">
                <button type="button" class="close" data-dismiss="modal" style="color:white;opacity:.8;">&times;</button>
                <h4 class="modal-title" id="qcaModalTitle">開立品質異常單</h4>
            </div>
            <div class="modal-body" style="padding:20px;">
                <input type="hidden" id="qca_bom_ing_fid">
                <input type="hidden" id="qca_qc_check_id">
                <input type="hidden" id="qca_edit_id">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group"><label>異常單號</label><input type="text" class="form-control" id="qca_no" placeholder="自動產生"></div>
                        <div class="form-group"><label>異常種類</label><select class="form-control" id="qca_abnormal_type"><option value="">請選擇...</option></select></div>
                        <div class="form-group"><label>異常發生日期</label><input type="date" class="form-control" id="qca_occurrence_date"></div>
                        <div class="form-group"><label>異常數量</label><input type="number" class="form-control" id="qca_sqty" placeholder="不良品數量"></div>
                        <div class="form-group"><label>責任單位</label><input type="text" class="form-control" id="qca_responsible_unit" placeholder="廠商/部門"></div>
                        <div class="form-group"><label>發現單位</label>
                            <select class="form-control" id="qca_found_unit">
                                <option value="">請選擇</option>
                                <option value="廠內">廠內</option>
                                <option value="客退">客退</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group"><label>異常現象描述</label><textarea class="form-control" id="qca_phenomenon" rows="4" placeholder="詳細描述異常現象..."></textarea></div>
                        <div class="form-group">
                            <label>5M+T 原因分類（單選）</label>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;">
                                <?php foreach(['人','機器','材料','方法','工具','環','其他'] as $m): ?>
                                <label style="display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;">
                                    <input type="radio" name="qca_defect_category" value="<?=$m?>" style="margin:0;"> <?=$m?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group"><label>原因詳細說明</label><textarea class="form-control" id="qca_defect_detail" rows="3" placeholder="異常原因詳細說明..."></textarea></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group"><label>品管備註</label><textarea class="form-control" id="qca_ps" rows="3" placeholder="品管判定備註..."></textarea></div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>異常處置方式</label>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;">
                                <?php foreach(['特採','報廢','重工','需矯正','轉總經理裁示'] as $d): ?>
                                <label style="display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;">
                                    <input type="checkbox" name="qca_disposition" value="<?=$d?>" style="margin:0;"> <?=$d?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group"><label>處置說明</label><textarea class="form-control" id="qca_disposition_note" rows="2" placeholder="處置說明..."></textarea></div>
                    </div>
                </div>
                <hr style="margin:10px 0;">
                <div class="form-group">
                    <label>回覆部門設定</label>
                    <div id="qca_dept_container" style="max-height:220px;overflow-y:auto;border:1px solid #eee;padding:8px;border-radius:4px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="qca_save_btn" onclick="saveQCQA()">確認開立</button>
            </div>
        </div></div>
    </div>

    <!-- ── QC 品質異常單詳情 Modal ── -->
    <div class="modal fade" id="qcaDetailModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" style="width:85%;"><div class="modal-content">
            <div class="modal-header" style="background:#2A3F54;color:white;border-radius:4px 4px 0 0;">
                <button type="button" class="close" data-dismiss="modal" style="color:white;opacity:.8;">&times;</button>
                <h4 class="modal-title">品質異常單詳情</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="qca_detail_order_id">
                <div class="row" id="qca_detail_info" style="margin-bottom:15px;"></div>
                <hr style="margin:10px 0;">
                <h5 style="font-weight:700;margin-bottom:10px;">相關單位回覆流程</h5>
                <table class="table table-bordered table-striped" style="font-size:13px;">
                    <thead><tr>
                        <th style="width:10%;">部門</th>
                        <th style="width:12%;">指定人員</th>
                        <th style="width:13%;">送交時間</th>
                        <th>回覆內容</th>
                        <th style="width:13%;">歸還時間</th>
                        <th style="width:22%;">操作</th>
                    </tr></thead>
                    <tbody id="qca_flow_tbody"><tr><td colspan="6" class="text-center"><i class="fa fa-spinner fa-spin"></i></td></tr></tbody>
                </table>
            </div>
            <div class="modal-footer" style="display:flex;justify-content:space-between;">
                <button type="button" class="btn btn-warning btn-sm" onclick="openEditQCAModal()"><i class="fa fa-pencil"></i> 編輯</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
            </div>
        </div></div>
    </div>

    <script>
    var QA_API = '../../src/store/store_QA_Abnormal_API.php';
    var qaAllDepts = [];
    var qaDeptUsersCache = {};

    $.post(QA_API, { action: 'get_all_depts' }, function(res) {
        if (res.success) qaAllDepts = res.data;
    }, 'json');

    function openQCCreateQAModal(bomIngFid, qcCheckId) {
        $('#qca_bom_ing_fid').val(bomIngFid);
        $('#qca_qc_check_id').val(qcCheckId);
        $('#qca_edit_id').val('');
        $('#qcaModalTitle').text('開立品質異常單（廠內）');
        $('#qca_save_btn').attr('onclick', 'saveQCQA()').text('確認開立');
        resetQCAForm();
        $.post(QA_API, { action: 'get_next_no' }, function(r) { if (r.success) $('#qca_no').val(r.no); }, 'json');
        $('#qca_found_unit').val('廠內');
        $('#qca_occurrence_date').val(new Date().toISOString().split('T')[0]);
        loadQCATypes();
        loadQCADeptContainer({});
        $('#qcaModal').modal('show');
    }

    function openQCQADetailModal(qaId) {
        $('#qca_detail_order_id').val(qaId);
        $('#qca_flow_tbody').html('<tr><td colspan="6" class="text-center"><i class="fa fa-spinner fa-spin"></i></td></tr>');
        $('#qca_detail_info').empty();
        loadQCADetail(qaId);
        $('#qcaDetailModal').modal('show');
    }

    function openEditQCAModal() {
        var orderId = parseInt($('#qca_detail_order_id').val());
        if (!orderId) return;
        $('#qcaDetailModal').modal('hide');
        $.post(QA_API, { action: 'get_detail', id: orderId }, function(res) {
            if (!res.success) { alert('載入失敗'); return; }
            var d = res.data;
            $('#qca_bom_ing_fid').val('');
            $('#qca_qc_check_id').val(d.source_id);
            $('#qca_edit_id').val(orderId);
            $('#qcaModalTitle').text('編輯品質異常單 — ' + d.abnormal_order_no);
            $('#qca_save_btn').attr('onclick', 'saveQCAEdit()').text('儲存修改');
            $('#qca_no').val(d.abnormal_order_no);
            loadQCATypes(d.abnormal_type_id);
            $('#qca_occurrence_date').val(d.occurrence_date || '');
            $('#qca_sqty').val(d.sqty || '');
            $('#qca_responsible_unit').val(d.responsible_unit || '');
            $('#qca_found_unit').val(d.found_unit || '');
            $('#qca_phenomenon').val(d.abnormal_phenomenon || '');
            $('input[name="qca_defect_category"]').prop('checked', false);
            if (d.defect_category) $('input[name="qca_defect_category"][value="' + d.defect_category + '"]').prop('checked', true);
            $('#qca_defect_detail').val(d.defect_detail || '');
            $('#qca_ps').val(d.qa_ps || '');
            $('#qca_disposition_note').val(d.disposition_note || '');
            $('input[name="qca_disposition"]').prop('checked', false);
            if (d.disposition) d.disposition.split(',').forEach(function(v) { $('input[name="qca_disposition"][value="' + v.trim() + '"]').prop('checked', true); });
            var existingFlows = {};
            if (d.flow) d.flow.forEach(function(f) { existingFlows[f.dept_id] = f; });
            loadQCADeptContainer(existingFlows);
            $('#qcaModal').modal('show');
        }, 'json');
    }

    function saveQCQA() {
        var data = buildQCAData();
        data.action = 'create'; data.source_type = 'QC'; data.source_id = $('#qca_qc_check_id').val();
        var btn = $('#qca_save_btn').prop('disabled', true).text('儲存中...');
        $.post(QA_API, data, function(r) {
            btn.prop('disabled', false).text('確認開立');
            if (r.success) { alert('異常單 ' + r.no + ' 已建立'); $('#qcaModal').modal('hide'); fetchAndUpdateData(qcPagination.page); }
            else alert('建立失敗：' + (r.message || ''));
        }, 'json');
    }

    function saveQCAEdit() {
        var data = buildQCAData();
        data.action = 'update'; data.id = $('#qca_edit_id').val();
        var btn = $('#qca_save_btn').prop('disabled', true).text('儲存中...');
        $.post(QA_API, data, function(r) {
            btn.prop('disabled', false).text('儲存修改');
            if (r.success) { $('#qcaModal').modal('hide'); openQCQADetailModal($('#qca_detail_order_id').val()); fetchAndUpdateData(qcPagination.page); }
            else alert('修改失敗：' + (r.message || ''));
        }, 'json');
    }

    function buildQCAData() {
        var depts = []; $('#qca_dept_container .qa-dept-check:checked').each(function() {
            var $r = $(this).closest('.row');
            depts.push({ dept_id: $(this).val(), mode: $r.find('.qa-dept-mode').val() || 0, user_id: $r.find('.qa-dept-user-select').val() || '' });
        });
        var disp = []; $('input[name="qca_disposition"]:checked').each(function() { disp.push($(this).val()); });
        return {
            abnormal_order_no: $('#qca_no').val(),
            occurrence_date: $('#qca_occurrence_date').val(),
            responsible_unit: $('#qca_responsible_unit').val(),
            found_unit: $('#qca_found_unit').val(),
            abnormal_phenomenon: $('#qca_phenomenon').val(),
            abnormal_type_id: $('#qca_abnormal_type').val(),
            defect_category: $('input[name="qca_defect_category"]:checked').val() || '',
            defect_detail: $('#qca_defect_detail').val(),
            disposition: disp.join(','),
            disposition_note: $('#qca_disposition_note').val(),
            qa_ps: $('#qca_ps').val(),
            sqty: $('#qca_sqty').val(),
            departments: JSON.stringify(depts)
        };
    }

    function loadQCADetail(orderId) {
        $.post(QA_API, { action: 'get_detail', id: orderId }, function(res) {
            if (!res.success) { alert('載入失敗'); return; }
            var d = res.data;
            var m5t  = d.defect_category ? '<span class="label label-default">' + d.defect_category + '</span>' : '-';
            var disp = d.disposition ? d.disposition.split(',').map(function(v) { return '<span class="label label-primary">' + v.trim() + '</span>'; }).join(' ') : '-';
            $('#qca_detail_info').html(
                '<div class="col-md-6"><table class="table table-condensed" style="font-size:13px;">' +
                '<tr><th style="width:35%;">異常單號</th><td><strong>' + d.abnormal_order_no + '</strong></td></tr>' +
                '<tr><th>異常種類</th><td>' + (d.abnormal_type_name||'-') + '</td></tr>' +
                '<tr><th>發生日期</th><td>' + (d.occurrence_date||'-') + '</td></tr>' +
                '<tr><th>異常數量</th><td>' + (d.sqty||'-') + '</td></tr>' +
                '<tr><th>責任單位</th><td>' + (d.responsible_unit||'-') + '</td></tr>' +
                '<tr><th>發現單位</th><td>' + (d.found_unit||'-') + '</td></tr>' +
                '</table></div>' +
                '<div class="col-md-6"><table class="table table-condensed" style="font-size:13px;">' +
                '<tr><th style="width:35%;">5M+T分類</th><td>' + m5t + '</td></tr>' +
                '<tr><th>原因說明</th><td>' + (d.defect_detail||'-') + '</td></tr>' +
                '<tr><th>處置方式</th><td>' + disp + '</td></tr>' +
                '<tr><th>處置說明</th><td>' + (d.disposition_note||'-') + '</td></tr>' +
                '<tr><th>品管備註</th><td>' + (d.qa_ps||'-') + '</td></tr>' +
                '</table></div>' +
                '<div class="col-md-12" style="margin-top:-10px;"><div class="well well-sm" style="min-height:40px;"><strong>異常現象：</strong>' + (d.abnormal_phenomenon||'(未填)') + '</div></div>'
            );
            renderQCAFlowTable(d.flow || [], orderId);
        }, 'json');
    }

    function renderQCAFlowTable(flows, orderId) {
        var lastFlowIdx = {};
        flows.forEach(function(f, i) { lastFlowIdx[f.dept_id] = i; });
        var html = '';
        if (!flows.length) { html = '<tr><td colspan="6" class="text-center text-muted">尚無流程</td></tr>'; }
        flows.forEach(function(f, idx) {
            var isReturned = f.status === 'Returned', isReceived = f.status === 'Received';
            var badge = isReturned ? '<span class="label label-success">已歸還</span>'
                : (isReceived ? '<span class="label label-info">處理中</span>' : '<span class="label label-warning">待送交</span>');
            var replyHtml = '', actionHtml = '', designee = f.receiver_name || '-';
            if (!isReturned) {
                if (!isReceived) {
                    if (!f.receiver_name) {
                        designee = '<select class="form-control input-sm" id="qca_flow_user_' + f.flow_id + '" style="min-width:100px;"><option value="">載入中...</option></select>';
                        loadQCAFlowUsers(f.dept_id, f.flow_id, f.include_mode);
                    }
                    actionHtml = '<button class="btn btn-xs btn-primary" onclick="qcaFlowReceive(' + f.flow_id + ',' + orderId + ')">送交</button>';
                    replyHtml = '<span class="text-muted">待送交</span>';
                } else {
                    replyHtml = '<textarea class="form-control input-sm" id="qca_flow_reply_' + f.flow_id + '" rows="2" onkeydown="checkQCAReplyEnter(event,' + f.flow_id + ',this)">' + (f.reply_content || '') + '</textarea>';
                    actionHtml = '<button class="btn btn-xs btn-success" onclick="qcaFlowReturn(' + f.flow_id + ',' + orderId + ')">歸還品管</button> <button class="btn btn-xs btn-danger" onclick="qcaFlowRollback(' + f.flow_id + ',\'Pending\',' + orderId + ')">退回</button>';
                }
            } else {
                replyHtml = '<div style="white-space:pre-wrap;max-height:60px;overflow-y:auto;">' + (f.reply_content || '(無回覆)') + '</div>';
                actionHtml = '<button class="btn btn-xs btn-default" disabled>已歸還</button> <button class="btn btn-xs btn-danger" onclick="qcaFlowRollback(' + f.flow_id + ',\'Received\',' + orderId + ')">退回</button>';
                if (lastFlowIdx[f.dept_id] === idx) actionHtml += ' <button class="btn btn-xs btn-warning" onclick="qcaFlowResend(' + f.flow_id + ',' + orderId + ')">再次送交</button>';
            }
            html += '<tr><td>' + f.dept_name + '</td><td>' + designee + '</td><td>' + badge + '<br><small>' + (f.receive_date||'-') + '</small></td><td>' + replyHtml + '</td><td><small>' + (f.return_date||'-') + '</small></td><td>' + actionHtml + '</td></tr>';
        });
        $('#qca_flow_tbody').html(html);
    }

    function loadQCAFlowUsers(deptId, flowId, mode) {
        mode = mode || 0;
        var ck = deptId + '_' + mode;
        var pop = function(u) { var s = $('#qca_flow_user_' + flowId).empty().append('<option value="">請選擇人員</option>'); (u||[]).forEach(function(x) { s.append('<option value="' + x.id + '">' + x.user_cname + (x.position_name ? ' ' + x.position_name : '') + '</option>'); }); };
        if (qaDeptUsersCache[ck]) { pop(qaDeptUsersCache[ck]); return; }
        $.post(QA_API, { action: 'get_dept_users', dept_id: deptId, mode: mode }, function(r) { if (r.success) { qaDeptUsersCache[ck] = r.data; pop(r.data); } }, 'json');
    }

    function qcaFlowReceive(fid, oid) { var s = $('#qca_flow_user_' + fid); var u = s.length ? s.val() : null; if (s.length && !u) { alert('請選擇送交對象'); return; } $.post(QA_API, { action: 'flow_receive', flow_id: fid, target_user_id: u||'' }, function(r) { if (r.success) loadQCADetail(oid); else alert('失敗'); }, 'json'); }
    function qcaFlowReturn(fid, oid) { var c = $('#qca_flow_reply_' + fid).val(); if (!c && !confirm('未填回覆，確定歸還？')) return; $.post(QA_API, { action: 'flow_return', flow_id: fid, reply_content: c }, function(r) { if (r.success) loadQCADetail(oid); else alert('失敗'); }, 'json'); }
    function qcaFlowRollback(fid, t, oid) { if (!confirm('確定退回？')) return; $.post(QA_API, { action: 'flow_rollback', flow_id: fid, target_status: t }, function(r) { if (r.success) loadQCADetail(oid); else alert('失敗：' + (r.message||'')); }, 'json'); }
    function qcaFlowResend(fid, oid) { if (!confirm('確定再次送交？')) return; $.post(QA_API, { action: 'flow_resend', flow_id: fid }, function(r) { if (r.success) loadQCADetail(oid); else alert('失敗'); }, 'json'); }
    function checkQCAReplyEnter(e, fid, el) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $.post(QA_API, { action: 'flow_save_reply', flow_id: fid, reply_content: el.value }, function(r) { if (r.success) { el.style.backgroundColor = '#dff0d8'; setTimeout(function() { el.style.backgroundColor = ''; }, 1000); } }, 'json'); } }

    function resetQCAForm() {
        $('#qca_no,#qca_sqty,#qca_responsible_unit,#qca_occurrence_date,#qca_phenomenon,#qca_defect_detail,#qca_ps,#qca_disposition_note').val('');
        $('#qca_abnormal_type,#qca_found_unit').val('');
        $('input[name="qca_defect_category"],input[name="qca_disposition"]').prop('checked', false);
        $('#qca_dept_container').empty();
    }

    function loadQCATypes(sel) {
        $.post(QA_API, { action: 'get_abnormal_types' }, function(r) {
            var s = $('#qca_abnormal_type').empty().append('<option value="">請選擇...</option>');
            if (r.success) r.data.forEach(function(t) { s.append('<option value="' + t.type_id + '">' + t.type_name + '</option>'); });
            if (sel) s.val(sel);
        }, 'json');
    }

    function loadQCADeptContainer(existingFlows) {
        $.post(QA_API, { action: 'get_dept_config' }, function(res) {
            var c = $('#qca_dept_container').empty();
            if (!res.success || !res.config.length) { c.html('<p class="text-danger">尚未設定可用部門，請先在 IR_Track 頁面設定。</p>'); return; }
            var cfgMap = {}; res.config.forEach(function(x) { cfgMap[x.id] = x.mode; });
            qaAllDepts.forEach(function(d) {
                if (!cfgMap.hasOwnProperty(d.id) && !existingFlows.hasOwnProperty(d.id)) return;
                var def = cfgMap[d.id] !== undefined ? cfgMap[d.id] : 0;
                var fl = existingFlows[d.id]; var chk = !!fl; var dis = !!(fl && fl.return_date);
                var cm = fl ? (fl.include_mode || 0) : def; var cu = fl ? (fl.user_id || null) : null;
                c.append('<div class="form-group row" style="margin-bottom:5px;border-bottom:1px solid #f0f0f0;padding:5px 0;">' +
                    '<div class="col-xs-4"><label style="font-weight:normal;"><input type="checkbox" class="qa-dept-check" value="' + d.id + '" ' + (chk?'checked':'') + (dis?' disabled':'') + '> ' + d.name + (dis?' (已回覆)':'') + '</label></div>' +
                    '<div class="col-xs-4"><select class="form-control input-sm qa-dept-mode" style="display:' + (chk?'block':'none') + '"' + (dis?' disabled':'') + '><option value="0" ' + (cm==0?'selected':'') + '>本部門</option><option value="1" ' + (cm==1?'selected':'') + '>含下級</option><option value="2" ' + (cm==2?'selected':'') + '>僅下級主管</option></select></div>' +
                    '<div class="col-xs-4" id="qca_uc_' + d.id + '" style="display:' + (chk?'block':'none') + '"><select class="form-control input-sm qa-dept-user-select" id="qca_u_' + d.id + '"' + (dis?' disabled':'') + '><option value="">指定人員(選填)</option></select></div>' +
                    '</div>');
                if (chk || cfgMap.hasOwnProperty(d.id)) loadQCADeptUsers(d.id, cm, cu);
            });
            $('#qca_dept_container .qa-dept-check').off('change').on('change', function() {
                var id = $(this).val(); var $r = $(this).closest('.row');
                if (this.checked) { $r.find('.qa-dept-mode, #qca_uc_' + id).show(); loadQCADeptUsers(id, $r.find('.qa-dept-mode').val(), null); }
                else { $r.find('.qa-dept-mode, #qca_uc_' + id).hide(); }
            });
            $('#qca_dept_container .qa-dept-mode').off('change').on('change', function() {
                var id = $(this).closest('.row').find('.qa-dept-check').val();
                loadQCADeptUsers(id, $(this).val(), null);
            });
        }, 'json');
    }

    function loadQCADeptUsers(deptId, mode, sel) {
        var ck = deptId + '_' + (mode || 0);
        var pop = function(u) { var s = $('#qca_u_' + deptId).empty().append('<option value="">指定人員(選填)</option>'); (u||[]).forEach(function(x) { s.append('<option value="' + x.id + '">' + x.user_cname + (x.position_name ? ' ' + x.position_name : '') + ((x.is_main==0)?'(兼)':'') + '</option>'); }); if (sel) s.val(sel); };
        if (qaDeptUsersCache[ck]) { pop(qaDeptUsersCache[ck]); return; }
        $.post(QA_API, { action: 'get_dept_users', dept_id: deptId, mode: mode || 0 }, function(r) { if (r.success) { qaDeptUsersCache[ck] = r.data; pop(r.data); } }, 'json');
    }
    </script>

    <!-- ── QC 統計 ─────────────────────────────────────────── -->
    <script>
    var _statsCache    = null;
    var _statsTab      = 'today'; // today | week | month | custom
    var _statsCustomDF = null;
    var _statsCustomDT = null;
    var _selectedDay   = null; // null=real today; 'YYYY-MM-DD'=navigated date

    function _he(s) { if (s == null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // ── 取得今天 YYYY-MM-DD ──
    function _today() { return new Date().toISOString().slice(0,10); }
    // ── 取得本週一 YYYY-MM-DD ──
    function _weekStart() {
        var d = new Date(); var day = d.getDay(); var diff = (day === 0 ? -6 : 1 - day);
        d.setDate(d.getDate() + diff); return d.toISOString().slice(0,10);
    }
    // ── 取得本月第一天 YYYY-MM-DD ──
    function _monthStart() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-01'; }

    function openStatsModal() {
        _statsTab = 'today';
        _selectedDay   = null;
        _statsCustomDF = null;
        _statsCustomDT = null;
        $('#statsModal').modal('show');
        var t = _today();
        $('#stats-date-from').val(t);
        $('#stats-date-to').val(t);
        _updateDayNavUI();
        fetchStatsData(function(data) { renderByTab(data); });
    }

    function switchStatsTab(tab) {
        _statsTab = tab;
        $('.stats-tab').removeClass('active');
        $('.stats-tab[data-tab="' + tab + '"]').addClass('active');
        $('#stats-custom-inputs').toggle(tab === 'custom');

        // 切換到今天時重置日期導航
        if (tab === 'today') {
            _selectedDay   = null;
            _statsCustomDF = null;
            _statsCustomDT = null;
            _updateDayNavUI();
        }

        if (tab !== 'custom') {
            if (_statsCache) { renderByTab(_statsCache); }
            else { fetchStatsData(function(d){ renderByTab(d); }); }
        }
    }

    function _updateDayNavUI() {
        var btn     = document.getElementById('stats-today-btn');
        var nextBtn = document.getElementById('stats-nav-next');
        if (!btn) return;
        if (_selectedDay) {
            btn.textContent = _selectedDay.substr(5); // MM-DD
            if (nextBtn) nextBtn.disabled = false;
        } else {
            btn.textContent = '今天';
            if (nextBtn) nextBtn.disabled = true;
        }
    }

    function navigateDay(delta) {
        var base;
        if (_selectedDay) {
            base = new Date(_selectedDay + 'T00:00:00');
        } else {
            base = new Date(); base.setHours(0,0,0,0);
        }
        base.setDate(base.getDate() + delta);

        var today = new Date(); today.setHours(0,0,0,0);
        if (base > today) return; // 不超過今天

        var ymd = base.getFullYear() + '-'
            + String(base.getMonth()+1).padStart(2,'0') + '-'
            + String(base.getDate()).padStart(2,'0');
        var todayYMD = _today();

        if (ymd >= todayYMD) {
            _selectedDay   = null;
            _statsCustomDF = null;
            _statsCustomDT = null;
        } else {
            _selectedDay   = ymd;
            _statsCustomDF = ymd;
            _statsCustomDT = ymd;
        }

        // 確保切換到今天 tab
        _statsTab = 'today';
        $('.stats-tab').removeClass('active');
        $('.stats-tab[data-tab="today"]').addClass('active');
        $('#stats-custom-inputs').hide();

        _updateDayNavUI();
        fetchStatsData(function(d) { renderByTab(d); });
    }

    function fetchStatsData(callback) {
        var $body = $('#stats-modal-body');
        $body.html('<div style="text-align:center;padding:40px;color:#8A9BB0;"><i class="fa fa-spinner fa-spin fa-2x"></i><br>載入中...</div>');

        var params = {};
        if (_statsCustomDF) params.date_from = _statsCustomDF;
        if (_statsCustomDT) params.date_to   = _statsCustomDT;

        $.getJSON('../../src/store/_fetch_qc_stats.php', params, function(data) {
            if (!data.success) {
                $body.html('<div style="color:#E74C3C;padding:20px;">載入失敗：' + (data.message || '未知錯誤') + '</div>');
                return;
            }
            _statsCache = data;
            if (callback) callback(data);
        }).fail(function(xhr) {
            var msg = '網路錯誤，請重試';
            try { var r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e){}
            $body.html('<div style="color:#E74C3C;padding:20px;">' + msg + '</div>');
        });
    }

    function renderByTab(data) {
        if (_statsTab === 'today') {
            if (_selectedDay && data.custom) {
                // 日期導航模式：顯示該日資料，趨勢用 trend_context（該日前30天）
                var cv = { total: parseInt(data.custom.total)||0, ok: parseInt(data.custom.ok_cnt)||0, ng: parseInt(data.custom.ng_cnt)||0 };
                var trend = (data.custom.trend_context && data.custom.trend_context.length) ? data.custom.trend_context : data.trend_30d;
                renderSinglePeriod(data, _selectedDay, cv, _selectedDay, _selectedDay, trend, '近30天趨勢（至 ' + _selectedDay + '）');
            } else {
                renderSinglePeriod(data, '今天', data.today, _today(), _today(), null, '近30天趨勢');
            }
        } else if (_statsTab === 'week') {
            renderSinglePeriod(data, '本週', data.week, _weekStart(), _today(), data.trend_week, '本週趨勢');
        } else if (_statsTab === 'month') {
            renderSinglePeriod(data, '本月', data.month, _monthStart(), _today(), data.trend_month, '本月趨勢');
        } else if (_statsTab === 'custom') {
            if (data.custom) {
                var isSingleDay = (_statsCustomDF && _statsCustomDF === _statsCustomDT);
                var lbl = isSingleDay ? _statsCustomDF : ((_statsCustomDF||'') + ' ~ ' + (_statsCustomDT||''));
                var cv  = { total: parseInt(data.custom.total)||0, ok: parseInt(data.custom.ok_cnt)||0, ng: parseInt(data.custom.ng_cnt)||0 };
                var tt  = isSingleDay ? ('日趨勢（' + _statsCustomDF + '）') : ('日趨勢（' + (_statsCustomDF||'') + ' ~ ' + (_statsCustomDT||'') + '）');
                renderSinglePeriod(data, lbl, cv, _statsCustomDF, _statsCustomDT, data.custom.trend, tt);
            } else {
                $('#stats-modal-body').html('<div style="color:#8A9BB0;padding:20px;text-align:center;">請選擇日期後按查詢</div>');
            }
        }
        updatePrintArea(data);
    }

    function renderSinglePeriod(data, label, period, dateFrom, dateTo, customTrend, trendTitle) {
        var total = period.total||0, ok = period.ok||0, ng = period.ng||0;

        // 主卡片（大）
        var okPct = total > 0 ? Math.round(ok/total*100) : 0;
        var ngPct = total > 0 ? Math.round(ng/total*100) : 0;
        var mainCard = '<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;">'
            // 大總計
            + '<div class="stats-card" style="flex:0 0 160px;">'
            + '<div class="sc-label">' + _he(label) + ' 總計</div>'
            + '<div class="sc-total">' + total + '</div>'
            + '<div class="sc-row"><span class="sc-ok"><i class="fa fa-check-circle"></i> OK&nbsp;' + ok + '</span>'
            + '<span class="sc-ng"><i class="fa fa-times-circle"></i> NG&nbsp;' + ng + '</span></div>'
            + '</div>'
            // 橫向比例條
            + '<div style="flex:1;min-width:200px;display:flex;flex-direction:column;justify-content:center;">'
            + '<div style="font-size:12px;color:#8A9BB0;margin-bottom:6px;">OK / NG 比例</div>'
            + '<div style="display:flex;height:20px;border-radius:4px;overflow:hidden;background:#F0F3F7;">'
            + (ok > 0 ? '<div style="width:' + okPct + '%;background:#27AE60;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;">' + (okPct>8?okPct+'%':'') + '</div>' : '')
            + (ng > 0 ? '<div style="width:' + ngPct + '%;background:#E74C3C;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;">' + (ngPct>8?ngPct+'%':'') + '</div>' : '')
            + (total===0 ? '<div style="width:100%;background:#E4E9F0;"></div>' : '')
            + '</div>'
            + '<div style="display:flex;gap:16px;margin-top:6px;font-size:12px;">'
            + '<span style="color:#27AE60;"><span style="display:inline-block;width:10px;height:10px;background:#27AE60;border-radius:2px;margin-right:4px;"></span>OK ' + ok + ' (' + okPct + '%)</span>'
            + '<span style="color:#E74C3C;"><span style="display:inline-block;width:10px;height:10px;background:#E74C3C;border-radius:2px;margin-right:4px;"></span>NG ' + ng + ' (' + ngPct + '%)</span>'
            + '</div></div>'
            + '</div>';

        // 趨勢
        var trendData = customTrend || data.trend_30d;
        var trendTitleText = trendTitle || '近30天趨勢';
        var trendHtml = '<p class="stats-section-title">' + trendTitleText + '</p>' + renderTrendBars(trendData);

        // 製程明細
        var processHtml = '';
        if (data.by_process && data.by_process.length) {
            processHtml = '<p class="stats-section-title" style="margin-top:16px;">本月各製程（前10）</p>';
            processHtml += '<table class="process-table"><thead><tr><th>製程</th><th>總計</th><th>OK</th><th>NG</th><th>比例</th></tr></thead><tbody>';
            data.by_process.forEach(function(row) {
                var rt = parseInt(row.total)||0, rok = parseInt(row.ok_cnt)||0, rng = parseInt(row.ng_cnt)||0;
                var rokPct = rt > 0 ? Math.round(rok/rt*100) : 0;
                var rngPct = rt > 0 ? Math.round(rng/rt*100) : 0;
                processHtml += '<tr>'
                    + '<td style="font-weight:600;">' + _he(row.ProcessName||'未設定') + '</td>'
                    + '<td style="color:#2E6DA4;font-weight:700;">' + rt + '</td>'
                    + '<td style="color:#27AE60;font-weight:700;">' + rok + '</td>'
                    + '<td style="color:#E74C3C;font-weight:700;">' + rng + '</td>'
                    + '<td style="min-width:100px;"><div class="process-bar-wrap">'
                    + '<span class="process-bar-ok" style="width:' + rokPct + '%;"></span>'
                    + '<span class="process-bar-ng" style="width:' + rngPct + '%;"></span>'
                    + '</div><small style="color:#8A9BB0;">' + rokPct + '% OK</small></td>'
                    + '</tr>';
            });
            processHtml += '</tbody></table>';
        }

        $('#stats-modal-body').html(mainCard + trendHtml + processHtml);
    }

    function renderTrendBars(trendData) {
        if (!trendData || trendData.length === 0) {
            return '<div style="color:#8A9BB0;font-size:12px;padding:8px 0;">無資料</div>';
        }
        var maxVal = Math.max.apply(null, trendData.map(function(d){ return (parseInt(d.ok_cnt)||0)+(parseInt(d.ng_cnt)||0); }));
        if (maxVal === 0) maxVal = 1;
        var height = 70;
        var html = '<div class="trend-chart-wrap"><div class="trend-chart">';
        trendData.forEach(function(d) {
            var ok = parseInt(d.ok_cnt)||0, ng = parseInt(d.ng_cnt)||0, total = ok+ng;
            var okH = Math.round(ok/maxVal*height), ngH = Math.round(ng/maxVal*height);
            var label = d.day ? d.day.substr(5) : '';
            html += '<div class="trend-bar-group">'
                + '<div class="trend-bar-inner" title="' + _he(d.day) + ': OK=' + ok + ' NG=' + ng + '">'
                + (ngH > 0 ? '<div class="trend-bar ng" style="height:' + ngH + 'px;"></div>' : '')
                + (okH > 0 ? '<div class="trend-bar ok" style="height:' + okH + 'px;"></div>' : '')
                + (total === 0 ? '<div style="height:3px;width:8px;background:#E4E9F0;border-radius:2px;"></div>' : '')
                + '</div><div class="trend-label">' + _he(label) + '</div>'
                + '</div>';
        });
        html += '</div></div>';
        return html;
    }

    function queryCustomStats() {
        var df = $('#stats-date-from').val();
        var dt = $('#stats-date-to').val();
        if (!df || !dt) { alert('請選擇起始與結束日期'); return; }
        if (df > dt) { alert('起始日期不可晚於結束日期'); return; }
        _statsCustomDF = df;
        _statsCustomDT = dt;
        fetchStatsData(function(d){ renderByTab(d); });
    }

    function updatePrintArea(data) {
        var now = new Date();
        var printDate = now.getFullYear() + '/' + (now.getMonth()+1) + '/' + now.getDate()
            + ' ' + now.getHours() + ':' + String(now.getMinutes()).padStart(2,'0');
        var tabLabels = { today:'今天', week:'本週', month:'本月', custom:'自訂區間' };
        var tabLabel  = tabLabels[_statsTab] || '';
        var rangeLabel = (_statsTab === 'custom' && _statsCustomDF && _statsCustomDT)
            ? (_statsCustomDF + ' ~ ' + _statsCustomDT) : tabLabel;

        var html = '<div style="font-family:sans-serif;padding:20px;max-width:860px;margin:0 auto;">'
            + '<h2 style="margin:0 0 4px;font-size:18px;">QC 檢驗統計報告</h2>'
            + '<p style="color:#888;font-size:12px;margin:0 0 16px;">統計區間：' + rangeLabel
            + '　　列印日期：' + printDate + '</p><hr style="margin:0 0 16px;">'
            + '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">'
            + _makePrintCard('今天',  data.today.total, data.today.ok, data.today.ng)
            + _makePrintCard('本週',  data.week.total,  data.week.ok,  data.week.ng)
            + _makePrintCard('本月',  data.month.total, data.month.ok, data.month.ng);
        if (data.custom) {
            html += _makePrintCard(rangeLabel, parseInt(data.custom.total)||0, parseInt(data.custom.ok_cnt)||0, parseInt(data.custom.ng_cnt)||0);
        }
        html += '</div>';
        if (data.by_process && data.by_process.length) {
            html += '<h3 style="font-size:14px;margin:0 0 8px;">本月各製程</h3>'
                + '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead>'
                + '<tr style="background:#f5f5f5;">'
                + '<th style="padding:6px 8px;text-align:left;border-bottom:1px solid #ccc;">製程</th>'
                + '<th style="padding:6px 8px;text-align:center;border-bottom:1px solid #ccc;">總計</th>'
                + '<th style="padding:6px 8px;text-align:center;border-bottom:1px solid #ccc;">OK</th>'
                + '<th style="padding:6px 8px;text-align:center;border-bottom:1px solid #ccc;">NG</th>'
                + '</tr></thead><tbody>';
            data.by_process.forEach(function(r) {
                html += '<tr><td style="padding:5px 8px;border-bottom:1px solid #eee;">' + (r.ProcessName||'未設定') + '</td>'
                    + '<td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;">' + (r.total||0) + '</td>'
                    + '<td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;color:#27AE60;">' + (r.ok_cnt||0) + '</td>'
                    + '<td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;color:#E74C3C;">' + (r.ng_cnt||0) + '</td>'
                    + '</tr>';
            });
            html += '</tbody></table>';
        }
        html += '</div>';
        document.getElementById('stats-print-area').innerHTML = html;
    }

    function _makePrintCard(label, total, ok, ng) {
        return '<div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:8px;padding:12px 16px;">'
            + '<div style="font-size:11px;color:#888;font-weight:600;">' + label + '</div>'
            + '<div style="font-size:24px;font-weight:800;color:#2E6DA4;">' + total + '</div>'
            + '<div style="font-size:12px;color:#555;">OK <strong style="color:#27AE60;">' + ok + '</strong>'
            + '&nbsp;&nbsp;NG <strong style="color:#E74C3C;">' + ng + '</strong></div>'
            + '</div>';
    }

    function printStats() {
        if (!_statsCache) { alert('請先載入統計資料'); return; }
        updatePrintArea(_statsCache);
        var content = document.getElementById('stats-print-area').innerHTML;
        var win = window.open('', '_blank', 'width=960,height=700');
        win.document.write(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>QC 檢驗統計</title>'
            + '<style>body{font-family:sans-serif;margin:0;padding:0;}'
            + '@media print{@page{margin:1cm;}}</style>'
            + '</head><body onload="window.print()">'
            + content
            + '</body></html>'
        );
        win.document.close();
    }
    </script>

</body>

</html>