<?php
session_start();

if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    // $_SESSION['lastpage']="../../index.php";
    header("Location:../../index.php"); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';
include '../../src/common/role_features_helper.php';


@$userName      = $_SESSION['user_cname'];
@$id            = $_SESSION['id'];

// 出貨單「清除資料」危險操作：僅系統管理員或「資料急救台」管理員(data_console_edit)可見/可用（使用者明確要求 2026-08-27）
$__features = rf_load_user_features($db, (int)$id);
$canClearIsList = rf_has_feature($__features, 'all') || rf_has_feature($__features, 'data_console_edit');
// 「補資料」分頁（報價單 ERP 匯入、訂購單補資料）：僅系統管理員（使用者明確要求 2026-10-13）
$isPageAdmin = rf_has_feature($__features, 'all');

/* =====================================================================
 * 【待刪除清單 2026-07-28】以下 4 個舊上傳區塊已由 ERP 直接匯入取代，
 * 目前只在前端加 style="display:none" 隱藏，HTML 與後端處理都還在，
 * 待使用者確認新流程無誤後再整段移除（頁內搜尋「待刪除 2026-07-28」可定位）：
 *   1. 新 BOM(N-new_bom)                        but=nb
 *   2. [針對製程輸入] 新 BOM(製程 N-BOM_ING_ok)  but=u5_NEW
 *   3. [針對轉製程中] 移轉紀錄(S-OK)             but=u5
 *   4. 加工單價(transfer_log)                    but=transfer_log
 * 移除時同步清 _upload_For_List.php 內對應 but 分支與頁尾 enterToSubmit 設定。
 * ===================================================================== */

// 查詢各匯入功能最近上傳紀錄（由 system_settings 記錄）
$uploadLogs = [];
try {
    $r = $db->query("SELECT setting_key, updated_at AS ts, updated_by AS creator, updated_by_id AS creator_id
                     FROM system_settings
                     WHERE setting_key IN (
                         'upload_bom_nb','upload_bom_ing_new','upload_bom_ing_s',
                         'upload_bom_nb_ok','upload_transfer_log','upload_transfer_log_raw',
                         'upload_is_erp','upload_ir_erp','upload_bom_erp','upload_bom_ing_s_erp',
                         'upload_quotation_erp'
                     )");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $uploadLogs[$row['setting_key']] = $row;
    }
} catch (Exception $e) {}

// 方便取用的別名
$bomLastUpdate         = $uploadLogs['upload_bom_nb']       ?? null;
$bomNbOkLastUpdate     = $uploadLogs['upload_bom_nb_ok']    ?? null;
$bomIngLastUpdate      = $uploadLogs['upload_bom_ing_new']  ?? null;
$bomIngSLastUpdate     = $uploadLogs['upload_bom_ing_s']    ?? null;
$transferLogLastUpdate = $uploadLogs['upload_transfer_log'] ?? null;
$transferLogRawLastUpdate = $uploadLogs['upload_transfer_log_raw'] ?? null;
$isLastImport          = $uploadLogs['upload_is_erp']       ?? null;
$irLastImport          = $uploadLogs['upload_ir_erp']       ?? null;
$bomErpLastImport      = $uploadLogs['upload_bom_erp']      ?? null;
$transferErpLastImport = $uploadLogs['upload_bom_ing_s_erp'] ?? null;
$quotationErpLastImport = $uploadLogs['upload_quotation_erp'] ?? null;

/* 匯入者姓名對應表
 * _upload_For_List.php 的 recordUploadLog() 寫的是：
 *   updated_by_id = $_SESSION['id']（user.id，準確）
 *   updated_by    = $_SESSION['user_cname'] ?: $_SESSION['userName']
 * 但 Login.php 只設 userName/id、沒設 user_cname，所以 updated_by 實際都落成
 * 登入帳號(022、e…)。顯示時優先用 updated_by_id 查中文姓名，其次才用帳號對應。 */
$uploaderById   = [];   // user.id        => 中文姓名
$uploaderByName = [];   // user_uname     => 中文姓名
try {
    $ru = $db->query("SELECT id, user_uname, user_cname FROM user");
    while ($u = $ru->fetch(PDO::FETCH_ASSOC)) {
        $uploaderById[(string)$u['id']] = $u['user_cname'];
        if (isset($u['user_uname']) && $u['user_uname'] !== '') {
            $uploaderByName[(string)$u['user_uname']] = $u['user_cname'];
        }
    }
} catch (Exception $e) {}

function lastUpdateBadge($info, $color = '#555') {
    global $uploaderById, $uploaderByName;
    if (!$info || empty($info['ts'])) return '<span style="font-size:11px;color:#aaa">尚無記錄</span>';
    $ts  = date('Y-m-d H:i', strtotime($info['ts']));
    $raw = trim((string)($info['creator'] ?? ''));
    $uid = (string)($info['creator_id'] ?? '');
    // 1) 先用 user.id 查；2) 再用登入帳號查；3) 都查不到就退回原值（不會變空白）
    $creator = $uploaderById[$uid] ?? $uploaderByName[$raw] ?? $raw;
    $creator = htmlspecialchars($creator);
    return "<span style=\"font-size:11px;color:{$color}\">⏱ 最近匯入：{$ts}　│　{$creator}</span>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>上傳 List</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">

    <!-- Bootstrap
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    Font Awesome -->
    <!-- <link href="../../resource/css/font-awesome.css" rel="stylesheet"> -->
    <!-- NProgress -->
    <!-- <link href="../../resource/css/nprogress.css" rel="stylesheet"> -->
    <!-- iCheck -->
    <!-- <link href="../../resource/css/green.css" rel="stylesheet"> -->
    <!-- Datatables -->
    <!-- <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/responsive.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css" rel="stylesheet"> -->
    <!-- Custom Theme Style -->
    <!-- <link href="../../resource/css/custom.css" rel="stylesheet"> -->
    <!-- <link href="../../resource/css/pages.css" rel="stylesheet"> -->

    <!-- 日期選單用 -->
    <link rel="stylesheet" href="http://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css">
</head>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<style>
#oeiBody tr.oei-picked > td { background: #FFE2A8 !important; }
#oeiBody tr.oei-picked > td:first-child { box-shadow: inset 3px 0 0 #DD5138; }
    .button-container {
        display: flex;
        flex-wrap: wrap;
    }

    .button-container form {
        margin-right: 5px;
        margin-bottom: 5px;
    }

    .btn-container {
        display: flex;
        gap: 5px;
    }

    .highlighted-row td {
        color: darkblue;
        font-weight: bold;
    }

    .scroll-to-top {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 50px;
        height: 50px;
        background-color: rgba(255, 255, 255, 0.5);
        color: black;
        border: none;
        border-radius: 50%;
        text-align: center;
        line-height: 50px;
        cursor: pointer;
        font-size: 12px;
        font-weight: bold;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        transition: all 0.3s;
        z-index: 1000;
    }

    .scroll-to-top:hover {
        background-color: rgba(255, 255, 255, 0.7);
    }

    .short-input {
        width: 30%; /* 修改這裡來調整 input 框的寬度 */
    }

</style>

<script>
    // 在頁面加載時恢復滾動位置
    window.addEventListener('load', function() {
        if (sessionStorage.scrollPosition) {
            window.scrollTo(0, sessionStorage.scrollPosition);
        }
        if (sessionStorage.highlightedRow) {
            var row = document.querySelector('tr[data-row-id="' + sessionStorage.highlightedRow + '"]');
            if (row) {
                row.classList.add('highlighted-row');
            }
        }
    });

    // 在頁面卸載時保存滾動位置
    window.onbeforeunload = function() {
        sessionStorage.scrollPosition = window.scrollY;
    };

    function highlightRow(rowId) {
        sessionStorage.highlightedRow = rowId;
    }

    // 回到頂端功能
    function scrollToTop() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // 按下更新或排機台自動上移至頂端
    function submitFormAndScrollToTop() {
        document.querySelector('form').submit();
        setTimeout(function() {
            window.scrollTo(0, 0);
        }, 100); // 延遲一點時間，讓表單提交完成
    }

    // 自動隱藏成功消息
    setTimeout(function() {
        var successMessage = document.getElementById('message');
        if (successMessage) {
            successMessage.style.display = 'none';
        }
    }, 3000); // 3秒後隱藏
</script>

<body class="nav-sm">

<div class="container body">
    <div class="main_container">

        <!-- side and top bar include -->
        <?php include '../partPage/sideAndTopBarMenu.html' ?>
        <!-- /side and top bar include -->
        <!-- 回頂端按鈕 -->
        <button class="scroll-to-top" onclick="scrollToTop()">回頂端</button>
        <!-- page content -->
        <div class="right_col" role="main">
            <div class="">

                <div class="page-title">
                    <div class="title_left">
                        <h4>
                            <?php
                            if (!empty($_GET['message'])) {
                                if ($_GET['message'] == "oth") {
                                    // 優先使用 Session 訊息，因為它可以儲存更複雜的內容（如換行）
                                    if (isset($_SESSION['upload_message'])) {
                                        $SUSS_MAG = $_SESSION['upload_message'];
                                        unset($_SESSION['upload_message']); // 顯示後清除 session 訊息
                                    } elseif (isset($_GET['msg'])) {
                                        // 備用方案：如果沒有 session 訊息，再使用 GET 的訊息
                                        $SUSS_MAG = urldecode($_GET['msg']);
                                    }
                            
                                    // 顯示訊息
                                    echo "<div class=\"alert alert-success fade in alert-dismissable\" id=\"success-message\">
                                            <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                            " . nl2br(htmlspecialchars($SUSS_MAG)) . "
                                          </div>";
                                }
                            }
                            
                            ?>
                            <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
                            <script>
                                $(document).ready(function(){
                                    // 移除自動關閉，改為手動關閉
                                    // setTimeout(function() {
                                    //     $("#success-message").alert('close');
                                    // 
                                    //     // Remove the "message" parameter from the URL
                                    //     var url = new URL(window.location.href);
                                    //     url.searchParams.delete('message');
                                    //     url.searchParams.delete('msg');
                                    //     window.history.replaceState({}, document.title, url);
                                    // }, 5000);
                                });
                            </script>
                        </h4>
                        <!-- <h3>Event <small>Live</small></h3> -->
                    </div>
                    <div class="clearfix"></div>
                    
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
    
                                <div class="x_title">
                                    <h2>上傳 List <small>(EXCEL批次更新)</small></h2>
                                    <div class="clearfix"></div>
                                </div>
    
                                <div class="x_content">
                                    <p class="text-muted font-13 m-b-30">
                                    </p>
                                    <?php if ($isPageAdmin): ?>
                                    <ul class="nav nav-tabs" id="uploadTabs" style="margin-bottom:12px">
                                        <li class="active"><a href="#tabErpImport" data-toggle="tab">ERP 匯入</a></li>
                                        <li><a href="#tabBackfill" data-toggle="tab">補資料 <small class="text-muted">(管理員)</small></a></li>
                                    </ul>
                                    <?php endif; ?>
                                    <div class="tab-content" style="border:0;padding:0">
                                    <div class="tab-pane active" id="tabErpImport">

                                    <!-- 訂單未交(order-OK) 匯入已於 2026-09-18 移除：該匯入最後一次執行是 2026-03-12，資料是未交清單快照不是全量訂單，留著只會被誤用；_upload_For_List.php 的 but=Order 分支同步移除 -->
                                    
                                    <!-- 上傳-BOM ERP直接匯入 (N.xlsx，2026-07-20新增，取代下方新BOM+新BOM製程兩步驟) -->
                                    <div style="background:#e3f2fd;border:2px solid #64b5f6;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form id="form_bom_erp" action="_upload_For_List.php?but=BOM_ERP" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_bom_erp">
                                                BOM/BOM製程 <b>ERP直接匯入</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">直接上傳 ERP 匯出的 N.xlsx<br>免先跑VBA轉檔，自動建立/更新BOM+製程</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_bom_erp" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_bom_erp" class="btn btn-warning">ERP匯入</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($bomErpLastImport, '#1565c0') ?></div>
                                        </div>
                                    </form>
                                    </div><!-- /淺藍底 -->

                                    <!-- ============================================================
                                         【待刪除 2026-07-28】新 BOM(N-new_bom)　but=nb
                                         已由上方「BOM ERP直接匯入」取代，目前僅前端 display:none 隱藏。
                                         使用者確認無誤後：刪除本 <form> 區塊，並一併清掉
                                         _upload_For_List.php 內 but=nb 的處理與下方 JS enterToSubmit 設定
                                              (file_new_bom / btn_upload_new_bom)。
                                         ============================================================ -->
                                    <form action="_upload_For_List.php?but=nb" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group" style="margin-bottom:6px">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file">新 BOM(N-new_bom)<small>(只接受.xls)</small><BR> <span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_new_bom" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_new_bom" class="btn btn-success">上傳</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($bomLastUpdate) ?></div>
                                        </div>
                                    </form>
                                    <!-- ============================================================
                                         【待刪除 2026-07-28】[針對製程輸入] 新 BOM(製程 N-BOM_ING_ok)　but=u5_NEW
                                         已由上方「BOM ERP直接匯入」取代，目前僅前端 display:none 隱藏。
                                         使用者確認無誤後：刪除本 <form> 區塊，並一併清掉
                                         _upload_For_List.php 內 but=u5_NEW 的處理與下方 JS enterToSubmit 設定
                                              (file_bom_ing_ok / btn_upload_bom_ing_ok)。
                                         ============================================================ -->
                                    <form action="_upload_For_List.php?but=u5_NEW" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group" style="margin-bottom:6px">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file">[針對製程輸入] 新 BOM(製程 N-BOM_ING_ok)<small>(只接受.xls)</small><BR> <span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_bom_ing_ok" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_bom_ing_ok" class="btn btn-success">上傳</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($bomIngLastUpdate) ?></div>
                                        </div>
                                    </form>

                                    <!-- ============================================================
                                         【待刪除 2026-07-28】[針對轉製程中] 移轉紀錄(S-OK)　but=u5
                                         已由下方「移轉紀錄 ERP直接匯入」(but=Transfer_ERP) 取代，目前僅前端 display:none 隱藏。
                                         使用者確認無誤後：刪除本 <form> 區塊，並一併清掉
                                         _upload_For_List.php 內 but=u5 的處理與下方 JS enterToSubmit 設定
                                              (file_transfer_record / btn_upload_transfer_record)。
                                         ============================================================ -->
                                    <form action="_upload_For_List.php?but=u5" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group" style="margin-bottom:6px">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file">[針對轉製程中] 移轉紀錄(S-OK)<small>(只接受.xls)</small><BR> <span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_transfer_record" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_transfer_record" class="btn btn-success">上傳</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($bomIngSLastUpdate) ?></div>
                                        </div>
                                    </form>

                                    <!-- 上傳-移轉紀錄 ERP直接匯入 (SupQuery原始檔，2026-07-20新增，取代上方S-OK兩步驟) -->
                                    <div style="background:#f3e5f5;border:2px solid #ba68c8;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form id="form_transfer_erp" action="_upload_For_List.php?but=Transfer_ERP" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_transfer_erp">
                                                移轉紀錄 <b>ERP直接匯入</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">直接上傳 ERP 匯出的移轉原始檔(SupQuery)<br>免先跑VBA轉檔，自動更新製程移轉狀態</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_transfer_erp" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_transfer_erp" class="btn btn-warning">ERP匯入</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($transferErpLastImport, '#6a1b9a') ?></div>
                                        </div>
                                    </form>
                                    </div><!-- /淺紫底 -->

                                    <form action="_upload_For_List.php?but=nb_ok" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:6px">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file">BOM 結案 ERP直接匯入<small>(只接受.xls)</small><BR> <span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_bom_closeout" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_bom_closeout" class="btn btn-success">上傳</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($bomNbOkLastUpdate) ?></div>
                                        </div>
                                    </form>

                                    <!-- 上傳-報工_含TG(新增bom_ing) 2025.02 ok — 已隱藏 -->
                                    <form action="_upload_For_List.php?but=reply_tg" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file">報工_含TG<small>(只接受.xls)</small><BR> <span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8"> <!-- 修改這行的 col-md 和 col-sm -->
                                                <div class="input-group">
                                                    <input type="file" id="file_reply_tg" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_reply_tg" class="btn btn-success">上傳</button>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </form>

                                    <!-- 上傳-出貨單 ERP直接匯入 (is_list) -->
                                    <div style="background:#e9f5ee;border:2px solid #81c784;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form id="form_is_list_erp" action="_upload_For_List.php?but=IS_List_ERP" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_is_list_erp">
                                                出貨單 <b>ERP直接匯入</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">自動偵測民國/西元年<br>只清空檔案涵蓋的日期區間後重匯</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_is_list_erp" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_is_list_erp" class="btn btn-warning">ERP匯入</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($isLastImport, '#2e7d32') ?></div>
                                        </div>
                                    </form>
                                    </div><!-- /霧綠底 -->

                                    <?php if ($canClearIsList): ?>
                                    <!-- 出貨單清除資料（危險操作）：僅系統管理員或「資料急救台」管理員(data_console_edit)可見，需操作確認密碼 -->
                                    <div style="background:#fdecea;border:2px solid #DD5138;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form class="form-horizontal form-label-left" novalidate onsubmit="return false">
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                出貨單 <b style="color:#DD5138">清除資料</b><small>(危險操作)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">ERP匯出資料有誤時，清除後可重新匯入<br>需操作確認密碼，僅資料急救台管理員可用</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <button type="button" id="btn_clear_is_list_new" class="btn btn-danger">清除…</button>
                                            </div>
                                        </div>
                                    </form>
                                    </div><!-- /淺紅底 -->
                                    <?php endif; ?>

                                    <!-- 上傳-退貨單 ERP直接匯入 (ir_track) -->
                                    <div style="background:#fce4ec;border:2px solid #f48fb1;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form id="form_ir_list_erp" action="_upload_For_List.php?but=IR_List_ERP" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_ir_list_erp">
                                                退貨單 <b>ERP直接匯入</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">確認「銷貨退回日報表」+「IR」開頭<br>只清空檔案涵蓋的日期區間後重匯</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_ir_list_erp" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_ir_list_erp" class="btn btn-warning">IR匯入</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($irLastImport, '#b71c1c') ?></div>
                                        </div>
                                    </form>
                                    </div><!-- /粉紅底 -->

                                    <!-- 報價單 ERP直接匯入 已移到「補資料」分頁（管理員專用） -->

                                    <!-- 上傳-出貨單 (is_list) — 已由 ERP直接匯入 取代，暫時隱藏 -->
                                    <form action="_upload_For_List.php?but=IS_List" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_is_list">出貨單 (is_list)<small>(只接受.xls/.xlsx)</small><br><span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_is_list" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_is_list" class="btn btn-success">上傳</button>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </form>

                                    <!-- ============================================================
                                         【待刪除 2026-07-28】加工單價(transfer_log)　but=transfer_log
                                         已由下方「更新加工單價 ERP原始檔直接匯入」(but=transfer_log_raw) 取代，目前僅前端 display:none 隱藏。
                                         使用者確認無誤後：刪除本 <form> 區塊，並一併清掉
                                         _upload_For_List.php 內 but=transfer_log 的處理與下方 JS enterToSubmit 設定
                                              (file_transfer_log / btn_upload_transfer_log)。
                                         ============================================================ -->
                                    <form action="_upload_For_List.php?but=transfer_log" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group" style="margin-bottom:6px">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_transfer_log">加工單價(transfer_log)<small>(只接受.xls/.xlsx)</small><br><span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_transfer_log" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_transfer_log" class="btn btn-success">上傳</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($transferLogLastUpdate) ?></div>
                                        </div>
                                    </form>

                                    <!-- 上傳-加工單價 ERP原始檔直接匯入 (transfer_log_raw) -->
                                    <div style="background:#e8eaf6;border:2px solid #7986cb;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form action="_upload_For_List.php?but=transfer_log_raw" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_transfer_log_raw">
                                                更新加工單價 <b>ERP原始檔直接匯入</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">資料來源＝ERP 的<b>製程移轉憑單</b><br>直接上傳 ERP 移轉紀錄原始檔<br>免 VBA 轉檔，民國年自動轉西元</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_transfer_log_raw" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_transfer_log_raw" class="btn btn-warning">原始檔匯入</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($transferLogRawLastUpdate, '#283593') ?></div>
                                        </div>
                                    </form>
                                    </div><!-- /藍紫底 -->
                                    </div><!-- /tabErpImport -->
<?php if ($isPageAdmin): ?>
                                    <div class="tab-pane" id="tabBackfill">
                                    <!-- 上傳-報價單 ERP直接匯入 (quotation_list，只新增不覆蓋既有單號，補建舊資料用) -->
                                    <div style="background:#F7E0BD;border:2px solid #E4C293;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form id="form_quotation_erp" action="_upload_For_List.php?but=Quotation_ERP_Preview" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_quotation_erp">
                                                報價單 <b>ERP直接匯入</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">ERP匯出「客戶報價單日報表」(型別需選客戶別明細表)<br>只新增，已存在單號一律跳過不動，用於補建舊資料</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_quotation_erp" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_quotation_erp" class="btn btn-warning">ERP匯入</button>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="col-md-5 col-sm-5 hidden-xs" style="padding-top:7px"><?= lastUpdateBadge($quotationErpLastImport, '#8a5a2b') ?></div>
                                        </div>
                                    </form>
                                    </div><!-- /暖砂琥珀底 -->
                                    <!-- 訂購單補資料（ERP「客戶訂購單日報表」→ 暫存區 → 轉成正式訂單） -->
                                    <div style="background:#FBEBD2;border:2px solid #E4C293;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form id="form_oei" class="form-horizontal form-label-left" novalidate onsubmit="return false">
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_oei">
                                                訂購單 <b>補資料</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="text-muted" style="font-size:11px;">ERP「客戶訂購單日報表」(客戶別 明細表)<br>只新增、不覆蓋；先進暫存區，確認後才轉成正式訂單<br>補入的訂單業務備註自動寫「補訂單資料」</span>
                                            </label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_oei" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn"><button type="button" id="btn_oei_preview" class="btn btn-warning">解析並比對</button></span>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                    </div>

                                    <div style="border:1px solid #E4C293;border-radius:6px;padding:8px 12px;background:#fff">
                                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px">
                                            <b>補資料暫存區</b>
                                            <div class="btn-group btn-group-sm" id="oeiStateTabs">
                                                <button type="button" class="btn btn-default active" data-st="pending">待轉入 <span class="badge" id="oeiCntPending">0</span></button>
                                                <button type="button" class="btn btn-default" data-st="converted">已轉入 <span class="badge" id="oeiCntConverted">0</span></button>
                                                <button type="button" class="btn btn-default" data-st="skipped">已略過 <span class="badge" id="oeiCntSkipped">0</span></button>
                                            </div>
                                            <input type="text" id="oeiQ" class="form-control input-sm" style="width:200px" placeholder="搜尋單號/產品編號/客戶" data-eg-skip>
                                            <select id="oeiStatus" class="form-control input-sm" style="width:150px" data-eg-skip title="依狀態篩選">
                                                <option value="">全部狀態</option><option value="new_order">新建訂單</option><option value="add_to_order">補進既有訂單</option>
                                                <option value="diff">同料號·內容不同</option><option value="new_part">自動建立料號</option><option value="no_part">料號主檔未對應</option>
                                            </select>
                                            <input type="text" id="oeiNote" class="form-control input-sm" style="width:200px" placeholder="篩選說明內容（如：補差額）" data-eg-skip>
                                            <span class="text-muted" style="font-size:12px" id="oeiShown"></span>
                                            <span style="flex:1"></span>
                                            <span id="oeiActs">
                                                <button type="button" class="btn btn-sm btn-success" id="btnOeiConvert"><i class="fa fa-exchange"></i> 轉成正式訂單（<span id="oeiSel">0</span>）</button>
                                                <button type="button" class="btn btn-sm btn-warning" id="btnOeiMulti" title="同客戶同編號在料號主檔有多筆時，左右對照後指定用哪一筆"><i class="fa fa-columns"></i> 料號多筆對照</button>
                                                <button type="button" class="btn btn-sm btn-warning" id="btnOeiCust"title="報表上的客戶名稱對不到客戶主檔時，指定它對應哪一家"><i class="fa fa-link"></i> 設定客戶對應</button>
                                                <button type="button" class="btn btn-sm btn-info" id="btnOeiCmp" title="同單號已有此產品但內容不同：左＝補資料、右＝訂單追蹤現有"><i class="fa fa-columns"></i> 內容不同比較表</button>
                                                <button type="button" class="btn btn-sm btn-default" id="btnOeiSkip">略過選取</button>
                                                <button type="button" class="btn btn-sm btn-danger" id="btnOeiDel">刪除選取</button>
                                            </span>
                                            <span id="oeiActsSk" style="display:none"><button type="button" class="btn btn-sm btn-default" id="btnOeiRestore">還原成待轉入</button> <button type="button" class="btn btn-sm btn-danger" id="btnOeiDel2">刪除選取</button></span>
                                        </div>
                                        <div id="oeiWrap" style="overflow-x:auto;max-height:520px;overflow-y:auto">
                                        <table class="table table-condensed table-bordered" style="font-size:12px;margin:0">
                                            <thead style="position:sticky;top:0;background:#F7E0BD"><tr>
                                                <th style="width:28px"><input type="checkbox" id="oeiAll"></th><th>狀態</th><th>訂單編號</th><th>單據日期</th><th>交期</th><th>客戶</th><th>產品編號</th><th>製程</th><th>數量</th><th>單價</th><th>說明</th>
                                            </tr></thead>
                                            <tbody id="oeiBody"><tr><td colspan="11" class="text-muted text-center">載入中…</td></tr></tbody>
                                        </table>
                                        </div>
                                        <div class="text-muted" style="font-size:11px;margin-top:6px">「轉成正式訂單」會在訂單追蹤新增該筆（業務備註＝補訂單資料），轉入當下會再比對一次，已有相同明細的自動略過；「同單號已有此產品但內容不同」預設不勾選，確認是另一筆明細才勾選補入。</div>
                                    <div style="margin-top:6px"><a href="price_fill_compare.php" target="_blank" class="btn btn-sm btn-warning"><i class="fa fa-columns"></i> 補單價比對（左：補資料／ERP，右：訂單追蹤，可批次轉價格）</a></div>
                                    </div>

<div class="modal fade" id="oeiMultiModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:1100px;max-width:96%">
        <div class="modal-content">
            <div class="modal-header" style="background:#F7E0BD"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><strong>料號主檔同編號多筆：左＝補資料，右＝候選料號主檔</strong></h4></div>
            <div class="modal-body" style="max-height:72vh;overflow:auto">
                <div class="text-muted" style="font-size:12px;margin-bottom:8px">同客戶、同產品編號在料號主檔有多筆，系統不猜。請依圖號／規格／版次／既有訂單數判斷，按右側「用這筆」，該組所有補資料列就會綁定該料號並變成可轉入。</div>
                <div id="oeiMultiBody"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
        </div>
    </div>
</div>
<div class="modal fade" id="oeiCmpModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:1100px;max-width:96%">
        <div class="modal-content">
            <div class="modal-header" style="background:#F7E0BD"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><strong>同料號·內容不同 比較表：左＝補資料（ERP），右＝訂單追蹤現有</strong></h4></div>
            <div class="modal-body" style="max-height:72vh;overflow:auto">
                <div class="text-muted" style="font-size:12px;margin-bottom:8px">依「單號＋產品編號」分組。紅字是兩邊不同的欄位；兩邊數量合計相同表示只是分批拆法不同。確定是另一筆明細才回到清單勾選補入，否則略過。<span id="oeiCmpInfo"></span></div>
                <div id="oeiCmpBody"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">關閉</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="oeiCustModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:860px;max-width:96%">
        <div class="modal-content">
            <div class="modal-header" style="background:#F7E0BD"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><strong>設定補資料客戶對應</strong></h4></div>
            <div class="modal-body" style="max-height:70vh;overflow:auto">
                <div class="text-muted" style="font-size:12px;margin-bottom:8px">下列是「料號主檔未對應」且客戶也對不上客戶主檔的報表客戶名稱。在右側輸入客戶名稱或編號挑選對應的客戶（已預填建議），按「儲存並重新判定」後，這些列會變成「自動建立料號」，轉入時會用該客戶建立料號。對應會記住，之後上傳同名客戶自動套用。</div>
                <datalist id="oeiCustList"></datalist>
                <div id="oeiCustBody"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                <button type="button" class="btn btn-warning" id="btnOeiCustSave">儲存並重新判定</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="oeiModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:1100px;max-width:96%">
        <div class="modal-content">
            <div class="modal-header" style="background:#F7E0BD"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><strong>訂購單補資料 — 比對結果</strong></h4></div>
            <div class="modal-body" id="oeiModalBody" style="max-height:70vh;overflow:auto"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-warning" id="btnOeiStage" style="display:none">存入暫存區（不會動訂單資料）</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery; if (!$ || !$('#form_oei').length) return;
    var API = '../../src/store/OrderErpImport_API.php', ST = 'pending';
    var LB = {
        new_order:   ['新建訂單', '#2e7d32'], add_to_order: ['補進既有訂單', '#8a5a2b'], exact: ['已存在·相同', '#888'],
        diff:        ['同料號·內容不同', '#DD5138'], new_part: ['自動建立料號', '#B8731F'], no_part: ['料號主檔未對應', '#b71c1c']
    };
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function fd(d) { return d ? String(d).replace(/-/g, '.') : ''; }
    function badge(st) { var l = LB[st] || [st, '#666']; return '<span style="display:inline-block;padding:1px 6px;border-radius:3px;color:#fff;background:' + l[1] + ';font-size:11px;line-height:16px;white-space:nowrap">' + l[0] + '</span>'; }
    function rowTds(r) {
        return '<td>' + badge(r.match_status) + '</td><td>' + esc(r.order_oo) + '</td><td>' + fd(r.order_date) + '</td><td>' + fd(r.delivery_date) +
            '</td><td>' + esc(r.client_name) + '</td><td>' + esc(r.part_no) + '</td><td>' + esc(r.process_text) + '</td><td style="text-align:right">' + esc(r.qty) +
            '</td><td style="text-align:right">' + (r.unit_price == null ? '' : Number(r.unit_price).toLocaleString()) + '</td><td>' + esc(r.match_note) + '</td>';
    }
    function post(act, data, fileForm) {
        return $.ajax({ url: API + '?action=' + act, type: 'POST', data: data, dataType: 'json', processData: !fileForm, contentType: fileForm ? false : 'application/x-www-form-urlencoded; charset=UTF-8' });
    }
    function fail(x) { var m = '伺服器錯誤'; try { m = JSON.parse(x.responseText).error || m; } catch (e) {} alert(m); }

    $('#btn_oei_preview').on('click', function () {
        var f = document.getElementById('file_oei').files[0];
        if (!f) { alert('請選擇檔案'); return; }
        var form = new FormData(); form.append('file', f);
        $('#oeiModalBody').html('<div class="text-center" style="padding:20px"><i class="fa fa-spinner fa-spin fa-3x"></i><p>解析並比對訂單資料中…</p></div>');
        $('#btnOeiStage').hide(); $('#oeiModal').appendTo('body').modal('show');
        post('preview', form, true).done(function (res) {
            if (!res.ok) { $('#oeiModalBody').html('<div class="alert alert-danger">' + esc(res.error) + '</div>'); return; }
            var c = res.counts, h = '<table class="table table-condensed table-bordered" style="font-size:13px;width:auto"><tr><td>解析到</td><td><b>' + res.total + '</b> 筆明細／' + res.orders + ' 張訂單</td></tr>';
            Object.keys(LB).forEach(function (k) { if (c[k]) h += '<tr><td>' + badge(k) + '</td><td><b>' + c[k] + '</b> 筆' + (k === 'exact' ? '（不會存入暫存區）' : k === 'new_part' ? '（轉入時自動建立料號並綁定客戶）' : k === 'no_part' ? '（存入後無法轉入，需先建料號主檔）' : '') + '</td></tr>'; });
            h += '</table>';
            if (res.warnings && res.warnings.length) h += '<div class="alert alert-warning" style="font-size:12px">' + res.warnings.map(esc).join('<br>') + '</div>';
            h += '<div style="overflow-x:auto"><table class="table table-condensed table-striped" style="font-size:12px;white-space:nowrap"><thead><tr><th>狀態</th><th>訂單編號</th><th>單據日期</th><th>交期</th><th>客戶</th><th>產品編號</th><th>製程</th><th>數量</th><th>單價</th><th>說明</th></tr></thead><tbody>';
            res.rows.forEach(function (r) { h += '<tr>' + rowTds(r) + '</tr>'; });
            h += '</tbody></table></div>';
            $('#oeiModalBody').html(h); $('#btnOeiStage').show().prop('disabled', false).text('存入暫存區（不會動訂單資料）');
        }).fail(function (x) { $('#oeiModalBody').html('<div class="alert alert-danger">解析失敗，請確認檔案格式</div>'); });
    });
    $('#btnOeiStage').on('click', function () {
        var b = $(this).prop('disabled', true).text('存入中…');
        post('stage', {}).done(function (res) {
            if (!res.ok) { alert(res.error); b.prop('disabled', false); return; }
            $('#oeiModal').modal('hide'); document.getElementById('file_oei').value = '';
            alert('已存入暫存區 ' + res.staged + ' 筆' + (res.exact ? '；訂單內已有相同明細 ' + res.exact + ' 筆已略過' : '') + (res.dup ? '；與暫存區既有待處理重複 ' + res.dup + ' 筆已略過' : ''));
            ST = 'pending'; load();
        }).fail(function (x) { fail(x); b.prop('disabled', false); });
    });

    // ── 客戶對應：報表客戶名稱 → 客戶主檔 ──
    var CUSTLBL = {};   // 「編號｜名稱」→ 編號
    function custLabel(c) { return c.customer_id + '｜' + c.customer; }
    function resolveCust(v) { v = $.trim(v); if (!v) return ''; if (CUSTLBL[v]) return CUSTLBL[v]; var m = v.match(/^([^｜|\s]+)[｜|]/); return m ? m[1] : v; }
    function loadCust() {
        $('#oeiCustBody').html('<div class="text-muted">載入中…</div>'); $('#oeiCustModal').appendTo('body').modal('show');
        post('cust_unmapped', {}).done(function (res) {
            if (!res.ok) { $('#oeiCustBody').html(esc(res.error)); return; }
            CUSTLBL = {}; var dl = '';
            res.customers.forEach(function (c) { var l = custLabel(c); CUSTLBL[l] = c.customer_id; dl += '<option value="' + esc(l) + '">'; });
            $('#oeiCustList').html(dl);
            var h = '';
            if (res.groups.length) {
                h += '<table class="table table-condensed table-bordered" style="font-size:12px"><thead><tr><th>報表客戶名稱</th><th>ERP編號</th><th>筆數</th><th style="width:330px">對應客戶主檔（輸入名稱／編號）</th></tr></thead><tbody>';
                res.groups.forEach(function (g) {
                    var pre = g.suggest && g.suggest.length === 1 ? custLabel(g.suggest[0]) : '';
                    var sg = (g.suggest || []).map(function (s) { return '<a href="#" class="oei-sg" data-v="' + esc(custLabel(s)) + '">' + esc(s.customer) + '</a>'; }).join('、');
                    h += '<tr><td>' + esc(g.client_name || '(空白)') + '</td><td>' + esc(g.client_id) + '</td><td>' + g.n + '</td><td><input class="form-control input-sm oei-cm" list="oeiCustList" data-name="' + esc(g.client_name) + '" value="' + esc(pre) + '" data-eg-skip>' + (sg ? '<div style="font-size:11px">建議：' + sg + '</div>' : '') + '</td></tr>';
                });
                h += '</tbody></table>';
            } else h += '<div class="alert alert-success">目前沒有客戶對不上的待轉入資料（先前設定的對應已生效，列在下方）。</div>';
            if (res.other && res.other.length) {
                h += '<b style="font-size:12px">客戶已對上，但料號仍無法對應（與客戶對應無關，需到料號主檔處理）</b><table class="table table-condensed table-bordered" style="font-size:12px"><thead><tr><th>原因</th><th>筆數</th><th>範例（客戶 料號）</th></tr></thead><tbody>';
                res.other.forEach(function (o) { h += '<tr><td>' + esc(o.note) + '</td><td>' + o.n + '</td><td>' + esc((o.parts || []).join('；')) + '</td></tr>'; });
                h += '</tbody></table>';
            }
            if (res.map.length) {
                h += '<b style="font-size:12px">已設定的對應</b><table class="table table-condensed table-bordered" style="font-size:12px"><tbody>';
                res.map.forEach(function (m) { h += '<tr><td>' + esc(m.erp_name) + '</td><td>' + esc(m.customer_id) + '｜' + esc(m.customer || '(客戶已不存在)') + '</td><td style="width:60px"><a href="#" class="oei-md" data-name="' + esc(m.erp_name) + '">取消</a></td></tr>'; });
                h += '</tbody></table>';
            }
            $('#oeiCustBody').html(h);
        }).fail(fail);
    }
    // ── 料號主檔同編號多筆：左右對照後指定 ──
    function loadMulti() {
        $('#oeiMultiBody').html('<div class="text-muted">載入中…</div>'); $('#oeiMultiModal').appendTo('body').modal('show');
        post('multi_list', {}).done(function (res) {
            if (!res.ok) { $('#oeiMultiBody').html(esc(res.error)); return; }
            if (!res.groups.length) { $('#oeiMultiBody').html('<div class="alert alert-success">目前沒有「料號主檔同編號多筆」的待轉入資料。</div>'); return; }
            var h = '<table class="table table-bordered" style="font-size:12px"><colgroup><col style="width:38%"><col></colgroup><thead><tr><th>補資料（ERP）</th><th>料號主檔候選</th></tr></thead><tbody>';
            res.groups.forEach(function (g) {
                h += '<tr><td><b>' + esc(g.client_name || '(空白)') + '</b>　產品編號 <b>' + esc(g.part_no) + '</b><br>共 ' + g.ids.length + ' 筆補資料' + (g.cust_ok ? '' : '<br><span style="color:#DD5138">客戶未對上主檔，候選為全部客戶</span>');
                g.rows.forEach(function (r) { h += '<div style="margin-top:4px;color:#555">' + esc(r.order_oo) + '　數量 ' + esc(r.qty) + '　單價 ' + (r.unit_price == null ? '' : esc(r.unit_price)) + '<br>' + esc(r.spec_text || '') + '</div>'; });
                if (g.ids.length > g.rows.length) h += '<div class="text-muted">…其餘 ' + (g.ids.length - g.rows.length) + ' 筆</div>';
                h += '</td><td><table class="table table-condensed" style="margin:0;font-size:12px"><thead><tr><th>#</th><th>客戶</th><th>圖號</th><th>規格</th><th>版次</th><th>建立</th><th>既有訂單</th><th></th></tr></thead><tbody>';
                g.cands.forEach(function (c) {
                    h += '<tr><td>' + c.d_id + '</td><td>' + esc(c.customer || c.Customer_Id || '') + '</td><td>' + esc(c.Drawing_No || '') + '</td><td>' + esc(c.Spec_No || '') + '</td><td>' + esc(c.Revision || '') + '</td><td>' + esc(String(c.Created_At || '').substr(0, 10)) + '</td><td>' + c.order_cnt + (c.last_order ? '（最近 ' + esc(String(c.last_order).substr(0, 10)) + '）' : '') + '</td>' +
                         '<td><button class="btn btn-xs btn-warning oei-mp" data-did="' + c.d_id + '" data-ids="' + g.ids.join(',') + '">用這筆</button></td></tr>';
                });
                h += '</tbody></table></td></tr>';
            });
            $('#oeiMultiBody').html(h + '</tbody></table>');
        }).fail(fail);
    }
    $('#btnOeiMulti').on('click', loadMulti);
    $(document).on('click', '.oei-mp', function () {
        var b = $(this); if (!confirm('該組全部補資料列都綁定料號主檔 #' + b.data('did') + '？')) return;
        b.prop('disabled', true);
        post('multi_pick', { d_id: b.data('did'), ids: JSON.stringify(String(b.data('ids')).split(',').map(Number)) }).done(function (r) {
            if (!r.ok) { alert(r.error); b.prop('disabled', false); return; }
            load(); loadMulti();
        }).fail(function (x) { fail(x); b.prop('disabled', false); });
    });
    $('#btnOeiCust').on('click', loadCust);
    $(document).on('click', '.oei-sg', function (e) { e.preventDefault(); $(this).closest('td').find('.oei-cm').val($(this).data('v')); });
    $(document).on('click', '.oei-md', function (e) {
        e.preventDefault(); var nm = $(this).data('name');
        if (!confirm('取消「' + nm + '」的客戶對應？')) return;
        post('cust_map_save', { maps: JSON.stringify([{ erp_name: nm, customer_id: '' }]) }).done(function (r) { if (!r.ok) alert(r.error); load(); loadCust(); }).fail(fail);
    });
    $('#btnOeiCustSave').on('click', function () {
        var maps = [], bad = '';
        $('#oeiCustBody .oei-cm').each(function () {
            var v = $.trim($(this).val()); if (!v) return;
            var id = resolveCust(v); if (!id || !Object.keys(CUSTLBL).some(function (k) { return CUSTLBL[k] === id; })) { bad = '「' + v + '」不是客戶主檔裡的客戶，請從清單挑選'; return false; }
            maps.push({ erp_name: $(this).data('name'), customer_id: id });
        });
        if (bad) { alert(bad); return; }
        if (!maps.length) { alert('尚未選擇任何對應'); return; }
        post('cust_map_save', { maps: JSON.stringify(maps) }).done(function (r) {
            if (!r.ok) { alert(r.error); return; }
            alert('已儲存 ' + r.saved + ' 筆對應，並重新判定 ' + r.rematched + ' 筆待轉入資料'); load(); loadCust();
        }).fail(fail);
    });

    // ── 同料號·內容不同 比較表 ──
    function fnum(v) { return v == null || v === '' ? '' : Number(v).toLocaleString(); }
    $('#btnOeiCmp').on('click', function () {
        $('#oeiCmpBody').html('<div class="text-muted">載入中…</div>'); $('#oeiCmpModal').appendTo('body').modal('show');
        post('diff_compare', { q: $('#oeiQ').val() }).done(function (res) {
            if (!res.ok) { $('#oeiCmpBody').html(esc(res.error)); return; }
            $('#oeiCmpInfo').text(res.total_groups > res.groups.length ? '（共 ' + res.total_groups + ' 組，只顯示前 ' + res.groups.length + ' 組，可用上方搜尋縮小）' : '（共 ' + res.groups.length + ' 組）');
            var h = '';
            res.groups.forEach(function (g) {
                var xp = {}, xpr = {}; g.xl.forEach(function (r) { xp[$.trim(r.process_text || '')] = 1; xpr[Number(r.unit_price)] = 1; });
                var same = g.xl_sum === g.ex_sum;
                h += '<table class="table table-condensed table-bordered" style="font-size:12px;margin-bottom:10px"><thead><tr style="background:#F7E0BD"><th colspan="2">' + esc(g.order_oo) + '　' + esc(g.client_name) + '　' + esc(g.part_no) + '</th><th colspan="2" style="text-align:right">補資料合計 <b style="color:' + (same ? '#2e7d32' : '#DD5138') + '">' + fnum(g.xl_sum) + '</b>　訂單追蹤合計 <b style="color:' + (same ? '#2e7d32' : '#DD5138') + '">' + fnum(g.ex_sum) + '</b></th></tr>' +
                    '<tr><th style="width:50%" colspan="2">補資料（ERP 報表）</th><th colspan="2">訂單追蹤現有</th></tr></thead><tbody>';
                var n = Math.max(g.xl.length, g.ex.length);
                for (var i = 0; i < n; i++) {
                    var a = g.xl[i], b = g.ex[i], l = '', rr = '';
                    if (a) {
                        l = '<td colspan="2">數量 <b>' + fnum(a.qty) + '</b>　製程 ' + esc(a.process_text || '(空)') + '　單價 ' + fnum(a.unit_price) + '　交期 ' + fd(a.delivery_date) + '</td>';
                    } else l = '<td colspan="2" class="text-muted">—</td>';
                    if (b) {
                        var dq = a && Number(a.qty) !== Number(b.Qty), dp = a && String($.trim(a.process_text || '')) !== '' && $.trim(b.Processing_items || '') !== '' && $.trim(a.process_text) !== $.trim(b.Processing_items), du = a && a.unit_price != null && b.unit_price != null && Math.abs(a.unit_price - b.unit_price) >= 0.005;
                        var red = function (t, f) { return f ? '<span style="color:#DD5138;font-weight:bold">' + t + '</span>' : t; };
                        rr = '<td colspan="2">#' + b.Order_id + '　數量 ' + red('<b>' + fnum(b.Qty) + '</b>', dq) + '　製程 ' + red(esc(b.Processing_items || '(空)'), dp) + '　單價 ' + red(fnum(b.unit_price), du) + '　交期 ' + fd(b.Delivery_date) + (b.Order_ps ? '　<span class="text-muted">' + esc(b.Order_ps) + '</span>' : '') + '</td>';
                    } else rr = '<td colspan="2" class="text-muted">—（訂單內沒有對應的列）</td>';
                    h += '<tr>' + l + rr + '</tr>';
                }
                h += '</tbody></table>';
            });
            $('#oeiCmpBody').html(h || '<div class="alert alert-success">目前沒有「同料號·內容不同」的待轉入資料。</div>');
        }).fail(fail);
    });

    function selIds() { return $('#oeiBody .oei-ck:checked').map(function () { return +this.value; }).get(); }
    function updSel() {
        $('#oeiSel').text(selIds().length);
        $('#oeiBody .oei-ck').each(function () { $(this).closest('tr').toggleClass('oei-picked', this.checked); });
    }
    function load() {
        post('list', { state: ST, q: $('#oeiQ').val() }).done(function (res) {
            if (!res.ok) return;
            ROWS = res.rows; CNT = res.counts; render();
        });
    }
    var ROWS = [], CNT = {};
    function render() {
        var res = { rows: ROWS, counts: CNT };
        var fs = $('#oeiStatus').val(), fn = $.trim($('#oeiNote').val()).toLowerCase();
        var all = res.rows;
        // 狀態篩選只列「目前分頁裡有資料」的狀態（沒資料的不顯示）
        var cntSt = {}; all.forEach(function (r) { cntSt[r.match_status] = (cntSt[r.match_status] || 0) + 1; });
        var ORDER = ['new_order', 'add_to_order', 'diff', 'new_part', 'no_part'], oh = '<option value="">全部狀態</option>';
        ORDER.forEach(function (k) { if (cntSt[k]) oh += '<option value="' + k + '">' + LB[k][0] + '（' + (cntSt[k] || 0) + '）</option>'; });
        if (fs && !cntSt[fs]) fs = '';   // 選到的狀態已沒資料就回到全部
        if ($('#oeiStatus').data('sig') !== oh) $('#oeiStatus').html(oh).data('sig', oh);
        $('#oeiStatus').val(fs);
        res.rows = all.filter(function (r) {
            if (fs && r.match_status !== fs) return false;
            if (fn && String(r.match_note || '').toLowerCase().indexOf(fn) < 0) return false;
            return true;
        });
        $('#oeiShown').text(res.rows.length === all.length ? '' : '顯示 ' + res.rows.length + ' / ' + all.length + ' 筆');
        {
            $('#oeiCntPending').text(res.counts.pending || 0); $('#oeiCntConverted').text(res.counts.converted || 0); $('#oeiCntSkipped').text(res.counts.skipped || 0);
            var h = '';
            res.rows.forEach(function (r) {
                var dis = (ST !== 'converted' && r.match_status === 'no_part') ? ' disabled' : '';
                var chk = (ST === 'pending' && (r.match_status === 'new_order' || r.match_status === 'add_to_order' || r.match_status === 'new_part')) ? ' checked' : '';
                var note = ST === 'converted' ? '已轉入 訂單 #' + esc(r.converted_order_id) + '　' + esc(r.converted_at || '') : esc(r.match_note);
                h += '<tr><td style="cursor:pointer;user-select:none">' + (ST === 'converted' ? '' : '<input type="checkbox" class="oei-ck" style="pointer-events:none" value="' + r.id + '"' + dis + chk + '>') + '</td>' + rowTds(r).replace(/<td>[^<]*<\/td>$/, '') + '<td>' + note + '</td></tr>';
            });
            $('#oeiBody').html(h || '<tr><td colspan="11" class="text-muted text-center">沒有資料</td></tr>');
            $('#oeiAll').prop('checked', false); updSel();
            $('#oeiActs').toggle(ST === 'pending'); $('#oeiActsSk').toggle(ST === 'skipped');
        }
    }
    $('#oeiStatus').on('change', render);
    $('#oeiNote').on('input', function () { clearTimeout(window._oeiT2); window._oeiT2 = setTimeout(render, 200); });
    // 框選：在「勾選欄／狀態欄」按住滑鼠拖出方框，碰到方框的列一次勾選（按住 Ctrl/Alt 改為取消勾選）
    // 沒拖動（單點）：點勾選欄那格＝切換該列。方框縮小時，離開方框的列會還原成拖曳前的狀態。
    // 拖曳超過 4px 才算框選，所以雙擊儲存格選字複製料號／單號仍可用。
    var mq = null;
    $('#oeiBody').on('mousedown', 'td:nth-child(-n+2)', function (e) {   // 只從「勾選欄／狀態欄」起拖，其他欄位保留選字
        if (e.which !== 1) return;
        var base = {}; $('#oeiBody .oei-ck').each(function () { base[this.value] = this.checked; });
        mq = { x: e.clientX, y: e.clientY, moved: false, base: base, firstCol: $(this).is(':first-child'), tr: $(this).closest('tr'), box: null, cx: e.clientX, cy: e.clientY };
    });
    function mqApply() {
        var x1 = Math.min(mq.x, mq.cx), x2 = Math.max(mq.x, mq.cx), y1 = Math.min(mq.y, mq.cy), y2 = Math.max(mq.y, mq.cy);
        mq.box.css({ left: x1, top: y1, width: x2 - x1, height: y2 - y1 });
        var val = !mq.cut;
        $('#oeiBody tr').each(function () {
            var ck = $(this).find('.oei-ck:not(:disabled)'); if (!ck.length) return;
            var rc = this.getBoundingClientRect(), hit = rc.bottom >= y1 && rc.top <= y2 && rc.right >= x1 && rc.left <= x2;
            ck.prop('checked', hit ? val : mq.base[ck.val()]);
        });
        updSel();
    }
    $(document).on('mousemove', function (e) {
        if (!mq) return;
        mq.cx = e.clientX; mq.cy = e.clientY; mq.cut = e.ctrlKey || e.altKey || e.metaKey;
        if (!mq.moved) {
            if (Math.abs(e.clientX - mq.x) < 4 && Math.abs(e.clientY - mq.y) < 4) return;
            mq.moved = true; window.getSelection && window.getSelection().removeAllRanges();
            mq.box = $('<div style="position:fixed;z-index:99999;border:1px solid #DD5138;background:rgba(240,162,75,.25);pointer-events:none"></div>').appendTo('body');
        }
        e.preventDefault();
        var w = document.getElementById('oeiWrap'), rc = w.getBoundingClientRect();   // 拖到表格邊緣自動捲動
        if (e.clientY > rc.bottom - 24) w.scrollTop += 20; else if (e.clientY < rc.top + 24) w.scrollTop -= 20;
        mqApply();
    });
    $(document).on('mouseup', function () {
        if (!mq) return;
        if (mq.moved) { mq.box.remove(); }
        else if (mq.firstCol) { var ck = mq.tr.find('.oei-ck:not(:disabled)'); if (ck.length) { ck.prop('checked', !ck.prop('checked')); updSel(); } }
        mq = null;
    });
    $('#oeiBody').on('selectstart', function (e) { if (mq && mq.moved) e.preventDefault(); });
    $('#oeiStateTabs button').on('click', function () { ST = $(this).data('st'); $('#oeiStateTabs button').removeClass('active'); $(this).addClass('active'); load(); });
    $('#oeiQ').on('input', function () { clearTimeout(window._oeiT); window._oeiT = setTimeout(load, 350); });
    $('#oeiAll').on('change', function () { $('#oeiBody .oei-ck:not(:disabled)').prop('checked', this.checked); updSel(); });
    $('#oeiBody').on('change', '.oei-ck', updSel);

    $('#btnOeiConvert').on('click', function () {
        var ids = selIds(); if (!ids.length) { alert('請先勾選要轉入的明細'); return; }
        // 點開即刷新：以後端最新狀態為準，轉入當下還會再比對一次
        if (!confirm('確定將選取的 ' + ids.length + ' 筆轉成正式訂單？\n只會新增，不會覆蓋任何既有訂單；業務備註會寫「補訂單資料」。')) return;
        var b = $(this).prop('disabled', true);
        post('convert', { ids: JSON.stringify(ids) }).done(function (res) {
            alert('已轉入 ' + res.converted + ' 筆' + (res.failed.length ? '\n未轉入 ' + res.failed.length + ' 筆：\n' + res.failed.slice(0, 10).join('\n') : ''));
        }).fail(fail).always(function () { b.prop('disabled', false); load(); });
    });
    function simple(act, msg) { return function () {
        var ids = selIds(); if (!ids.length) { alert('請先勾選'); return; }
        if (msg && !confirm(msg.replace('N', ids.length))) return;
        post(act, { ids: JSON.stringify(ids) }).done(load).fail(fail);
    }; }
    $('#btnOeiSkip').on('click', simple('skip'));
    $('#btnOeiRestore').on('click', simple('restore'));
    $('#btnOeiDel, #btnOeiDel2').on('click', simple('delete', '確定從暫存區刪除選取的 N 筆？（不影響訂單資料）'));
    load();
});
</script>

                                    </div><!-- /tabBackfill -->
<?php endif; ?>
                                    </div><!-- /tab-content -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>



<script src="../../code/highcharts.js"></script>
            <script src="../../code/modules/exporting.js"></script>
            <script src="../../code/modules/export-data.js"></script>
            <script src="../../code/modules/accessibility.js"></script>
            <!-- /page content -->
        <!-- footer content include -->
        <?php include '../partPage/footer.html' ?>
        <!-- /footer content include -->
    </div>
</div>

<!-- jQuery -->
<script src="../../resource/js/jquery.min.js"></script>
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
<script src="../../resource/js/custom.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileConfig = [
        { inputId: 'file_new_bom', buttonId: 'btn_upload_new_bom' },
        { inputId: 'file_bom_ing_ok', buttonId: 'btn_upload_bom_ing_ok' },
        { inputId: 'file_transfer_record', buttonId: 'btn_upload_transfer_record' },
        { inputId: 'file_bom_closeout', buttonId: 'btn_upload_bom_closeout' },
        { inputId: 'file_reply_tg', buttonId: 'btn_upload_reply_tg' },
        { inputId: 'file_is_list_erp', buttonId: 'btn_upload_is_list_erp' },
        { inputId: 'file_ir_list_erp', buttonId: 'btn_upload_ir_list_erp' },
        { inputId: 'file_quotation_erp', buttonId: 'btn_upload_quotation_erp' },
        { inputId: 'file_is_list', buttonId: 'btn_upload_is_list' },
        { inputId: 'file_transfer_log', buttonId: 'btn_upload_transfer_log' },
        { inputId: 'file_transfer_log_raw', buttonId: 'btn_upload_transfer_log_raw' }
    ];

    fileConfig.forEach(config => {
        const fileInput = document.getElementById(config.inputId);
        const uploadButton = document.getElementById(config.buttonId);

        if (fileInput && uploadButton) {
            // Initially hide the button
            uploadButton.style.display = 'none';

            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    uploadButton.style.display = ''; // Show button (default display by removing inline style)
                } else {
                    uploadButton.style.display = 'none'; // Hide button
                }
            });
        }
    });

    // 出貨單清除資料（危險操作，僅資料急救台管理員/系統管理員可見此按鈕；按下後才在跳窗內選年度/月份＋輸入操作確認密碼）
    const $isClearYear = $('#isClearYear'), $isClearMonth = $('#isClearMonth');
    if ($isClearYear.length) {
        const curY = new Date().getFullYear();
        for (let y = curY; y >= curY - 8; y--) $isClearYear.append('<option value="'+y+'">'+y+'年</option>');
        for (let m = 1; m <= 12; m++) $isClearMonth.append('<option value="'+m+'">'+m+'月</option>');

        let clearIsListPreviewSeq = 0;
        function updateClearIsListMsg() {
            const mode = $('input[name=isClearMode]:checked').val();
            const year = $isClearYear.val(), month = $isClearMonth.val();
            $isClearMonth.prop('disabled', mode !== 'month');
            let desc;
            if (mode === 'year_only') desc = '「' + year + '年」整個年度（僅該年，不影響其他年度）';
            else if (mode === 'year') desc = '「' + year + '年1月1日」之後的所有';
            else desc = '「' + year + '年' + month + '月1日」之後的所有';
            $('#clearIsListMsg').text('確定要清除 ' + desc + ' 出貨單資料（含綁定的出貨-BOM對應、出貨-訂單對應）嗎？此操作無法復原，請輸入操作確認密碼：');

            // 即時查詢目前符合此條件的實際筆數，避免選到沒有資料的範圍卻誤以為清除失敗（見鐵律6「點開即刷新」）
            const seq = ++clearIsListPreviewSeq;
            $('#clearIsListCount').text('查詢符合筆數中…');
            $.getJSON('../../src/store/_clear_is_list.php', {action: 'preview', mode: mode, year: year, month: month}, function(res) {
                if (seq !== clearIsListPreviewSeq) return; // 使用者已切換其他條件，這筆回應過期不採用
                $('#clearIsListCount').text(res.success ? ('目前符合此條件的資料共 ' + res.count + ' 筆') : (res.message || '查詢筆數失敗'));
            }).fail(function() {
                if (seq !== clearIsListPreviewSeq) return;
                $('#clearIsListCount').text('查詢筆數失敗');
            });
        }
        $('input[name=isClearMode], #isClearYear, #isClearMonth').on('change', updateClearIsListMsg);

        $('#btn_clear_is_list_new').on('click', function() {
            $('input[name=isClearMode][value=month]').prop('checked', true);
            $isClearYear.val(curY);
            $isClearMonth.val(new Date().getMonth() + 1);
            $('#clearIsListPwd').val('');
            $('#clearIsListErr').text('');
            updateClearIsListMsg();
            $('#clearIsListModal').modal('show');
            setTimeout(function() { $('#clearIsListPwd').trigger('focus'); }, 300);
        });

        $(document).on('keydown', '#clearIsListPwd', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#btnClearIsListConfirm').trigger('click'); }
        });

        $('#btnClearIsListConfirm').on('click', function() {
            const pwd = $('#clearIsListPwd').val();
            if (!pwd) { $('#clearIsListErr').text('請輸入密碼'); return; }
            $.post('../../src/store/_clear_is_list.php', {
                mode: $('input[name=isClearMode]:checked').val(),
                year: $isClearYear.val(), month: $isClearMonth.val(), confirm_password: pwd
            }, function(res) {
                if (res.success) {
                    $('#clearIsListModal').modal('hide');
                    alert('成功清除 ' + res.deleted_rows + ' 筆記錄。');
                    window.location.reload();
                } else {
                    $('#clearIsListErr').text(res.message || '清除失敗');
                }
            }, 'json').fail(function() {
                $('#clearIsListErr').text('連線失敗，請稍後再試');
            });
        });
    }
});

// ── ERP 匯入：兩步驟 AJAX（預覽 → 確認）────────────────────────────────────
$(document).ready(function() {

    $('#form_is_list_erp').on('submit', function(e) {
        e.preventDefault();

        var fileInput = document.getElementById('file_is_list_erp');
        if (!fileInput || !fileInput.files.length) {
            alert('請選擇檔案');
            return;
        }

        // 顯示「解析中」畫面並開啟 Modal
        $('#erpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在解析檔案，請稍候...</p>' +
            '</div>'
        );
        $('#btnERPConfirm').hide();
        $('#erpImportModal').modal('show');

        var fd = new FormData();
        fd.append('file', fileInput.files[0]);

        $.ajax({
            url: '_upload_For_List.php?but=IS_List_ERP_Preview',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (!res.success) {
                    var errHtml = '<div class="alert alert-danger"><strong>無法匯入，驗證失敗</strong>';
                    if (res.errors && res.errors.length) {
                        errHtml += '<ul style="margin:8px 0 0 0;padding-left:18px">';
                        res.errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                        errHtml += '</ul>';
                    } else {
                        errHtml += '<br>' + res.message;
                    }
                    errHtml += '</div>';
                    $('#erpModalBody').html(errHtml);
                    return;
                }

                var html = '<table class="table table-condensed table-bordered" style="font-size:13px">';

                // 格式檢查
                if (res.header_ok) {
                    html += '<tr><td>欄位格式</td><td><span class="label label-success">✓ 找到標準欄位（單據日期、單據號碼）</span></td></tr>';
                } else {
                    html += '<tr><td>欄位格式</td><td><span class="label label-warning">⚠ 未找到標準欄位標題</span></td></tr>';
                }

                html += '<tr><td>解析到的有效列數</td><td><strong>' + res.total_rows + ' 筆</strong></td></tr>';
                html += '<tr><td>資料日期範圍</td><td>' + res.date_min + ' ～ ' + res.date_max + '</td></tr>';
                html += '<tr class="danger"><td>將清除的舊資料</td><td><strong>' + res.existing_delete_count + ' 筆</strong>（' + res.date_min + ' ～ ' + res.date_max + ' 區間內，不影響此區間外的既有資料）</td></tr>';
                html += '<tr><td>將新增的資料</td><td><strong>' + res.total_rows + ' 筆</strong></td></tr>';
                html += '<tr><td>料號綁定</td><td>' + res.bound_count + ' / ' + res.total_rows + ' 筆成功綁定（含客戶驗證）</td></tr>';
                if (res.auto_create_count > 0)
                    html += '<tr class="warning"><td>將自動建立料號</td><td><strong>' + res.auto_create_count + ' 筆</strong>（確認匯入時建立，備註：匯入出貨單自動建立）</td></tr>';
                if (res.is_numbers_sample && res.is_numbers_sample.length) {
                    html += '<tr><td>單號範例</td><td><small>' + res.is_numbers_sample.join('、') + '</small></td></tr>';
                }
                html += '</table>';

                // 警告
                if (res.warnings && res.warnings.length) {
                    html += '<div class="alert alert-warning" style="font-size:13px"><strong>⚠ 注意事項</strong><ul style="margin:5px 0 0 0;padding-left:18px">';
                    res.warnings.forEach(function(w) { html += '<li>' + w + '</li>'; });
                    html += '</ul></div>';
                }

                // DEBUG：前10筆資料預覽（IS_DEBUG_PREVIEW = false 可隱藏）
                var IS_DEBUG_PREVIEW = true;
                if (IS_DEBUG_PREVIEW && res.preview_rows && res.preview_rows.length) {
                    html += '<div class="panel panel-default" style="margin-top:8px">';
                    html += '<div class="panel-heading" style="padding:6px 10px;cursor:pointer;font-size:12px;background:#e9f5ee" onclick="$(this).next().toggle()">' +
                            '▶ DEBUG：前 5 筆資料預覽（點擊展開/收合）</div>';
                    html += '<div class="panel-body" style="padding:5px;display:block;overflow-x:auto">';
                    html += '<table class="table table-condensed table-striped" style="font-size:11px;white-space:nowrap;margin:0">';
                    html += '<thead><tr><th>#</th><th>出貨日期</th><th>出貨單號</th><th>客戶</th><th>料號</th><th>綁定ID</th><th>品名(左)</th><th>內容(右)</th><th>數量</th><th>單價</th><th>備註</th></tr></thead><tbody>';
                    res.preview_rows.forEach(function(r, i) {
                        var bStyle = r.d_setting_id ? 'color:green' : 'color:#999';
                        var bVal   = r.d_setting_id ? '✓ ' + r.d_setting_id : '✗';
                        html += '<tr>';
                        html += '<td>' + (i + 1) + '</td>';
                        html += '<td>' + (r.order_date || '') + '</td>';
                        html += '<td>' + (r.is_number || '') + '</td>';
                        html += '<td>' + (r.client_name || '') + '</td>';
                        html += '<td>' + (r.product_id || '') + '</td>';
                        html += '<td style="' + bStyle + '">' + bVal + '</td>';
                        html += '<td style="color:#666">' + (r.specification || '<em>-</em>') + '</td>';
                        html += '<td><strong>' + (r.content || '') + '</strong></td>';
                        html += '<td>' + (r.qty || 0) + '</td>';
                        html += '<td>' + (r.unit_price || 0) + '</td>';
                        html += '<td>' + (r.note || '') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div></div>';
                }

                html += '<div class="alert alert-danger" style="font-size:12px;margin-bottom:0">' +
                        '<strong>此操作無法復原！</strong>確認後將立即清除舊資料並匯入新資料。' +
                        '</div>';

                $('#erpModalBody').html(html);
                $('#btnERPConfirm').show();
            },
            error: function() {
                $('#erpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
            }
        });
    });

    // 確認匯入
    $('#btnERPConfirm').on('click', function() {
        $(this).prop('disabled', true).text('匯入中...');
        $('#erpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在匯入資料，請勿關閉視窗...</p>' +
            '</div>'
        );

        $.ajax({
            url: '_upload_For_List.php?but=IS_List_ERP_Commit',
            type: 'POST',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#erpModalBody').html(
                        '<div class="alert alert-success"><strong>匯入成功！</strong><br>' +
                        '清除舊資料：' + res.deleted_rows + ' 筆<br>' +
                        '新增資料：' + res.inserted_rows + ' 筆' +
                        '</div>'
                    );
                    $('#btnERPConfirm').hide();
                    setTimeout(function() { window.location.reload(); }, 2000);
                } else {
                    $('#erpModalBody').html('<div class="alert alert-danger"><strong>匯入失敗</strong><br>' + res.message + '</div>');
                    $('#btnERPConfirm').prop('disabled', false).text('確認匯入');
                }
            },
            error: function() {
                $('#erpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
                $('#btnERPConfirm').prop('disabled', false).text('確認匯入');
            }
        });
    });
});
</script>

<script>
// ── 退貨單 IR 匯入：兩步驟 AJAX ──────────────────────────────────────────────
$(document).ready(function() {

    // DEBUG 開關：設為 false 可隱藏前10筆預覽
    var IR_DEBUG_PREVIEW = true;

    $('#form_ir_list_erp').on('submit', function(e) {
        e.preventDefault();

        var fileInput = document.getElementById('file_ir_list_erp');
        if (!fileInput || !fileInput.files.length) { alert('請選擇檔案'); return; }

        $('#irModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在解析退貨單資料，請稍候...</p>' +
            '</div>'
        );
        $('#btnIRConfirm').hide();
        $('#irImportModal').modal('show');

        var fd = new FormData();
        fd.append('file', fileInput.files[0]);

        $.ajax({
            url: '_upload_For_List.php?but=IR_List_ERP_Preview',
            type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(res) {
                if (!res.success) {
                    var errHtml = '<div class="alert alert-danger"><strong>無法匯入，驗證失敗</strong>';
                    if (res.errors && res.errors.length) {
                        errHtml += '<ul style="margin:8px 0 0 0;padding-left:18px">';
                        res.errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                        errHtml += '</ul>';
                    } else { errHtml += '<br>' + res.message; }
                    errHtml += '</div>';
                    $('#irModalBody').html(errHtml); return;
                }

                var html = '<table class="table table-condensed table-bordered" style="font-size:13px">';
                html += '<tr><td>欄位格式</td><td>' +
                    (res.header_ok
                        ? '<span class="label label-success">✓ 找到標準欄位（單據日期、單據號碼）</span>'
                        : '<span class="label label-warning">⚠ 未找到標準欄位標題</span>') + '</td></tr>';
                html += '<tr><td>解析到的有效列數</td><td><strong>' + res.total_rows + ' 筆</strong></td></tr>';
                html += '<tr><td>資料日期範圍</td><td>' + res.date_min + ' ～ ' + res.date_max + '</td></tr>';
                html += '<tr class="danger"><td>將清除的舊資料</td><td><strong>' + res.existing_delete_count + ' 筆</strong>（' + res.date_min + ' ～ ' + res.date_max + ' 區間內，不影響此區間外的既有資料）</td></tr>';
                html += '<tr><td>將新增的資料</td><td><strong>' + res.total_rows + ' 筆</strong></td></tr>';
                html += '<tr><td>料號綁定</td><td>' + res.bound_count + ' / ' + res.total_rows + ' 筆成功綁定（含客戶驗證）</td></tr>';
                if (res.auto_create_count > 0)
                    html += '<tr class="warning"><td>將自動建立料號</td><td><strong>' + res.auto_create_count + ' 筆</strong>（確認匯入時建立，備註：匯入退貨單自動建立）</td></tr>';
                if (res.ir_numbers_sample && res.ir_numbers_sample.length)
                    html += '<tr><td>退貨單號範例</td><td><small>' + res.ir_numbers_sample.join('、') + '</small></td></tr>';
                html += '</table>';

                if (res.warnings && res.warnings.length) {
                    html += '<div class="alert alert-warning" style="font-size:13px"><strong>⚠ 注意事項</strong><ul style="margin:5px 0 0 0;padding-left:18px">';
                    res.warnings.forEach(function(w) { html += '<li>' + w + '</li>'; });
                    html += '</ul></div>';
                }

                // DEBUG：前10筆資料預覽（IR_DEBUG_PREVIEW = false 可隱藏）
                if (IR_DEBUG_PREVIEW && res.preview_rows && res.preview_rows.length) {
                    html += '<div class="panel panel-default" style="margin-top:8px">';
                    html += '<div class="panel-heading" style="padding:6px 10px;cursor:pointer;font-size:12px" onclick="$(this).next().toggle()">' +
                            '▶ DEBUG：前 5 筆資料預覽（點擊展開/收合）</div>';
                    html += '<div class="panel-body" style="padding:5px;display:block;overflow-x:auto">';
                    html += '<table class="table table-condensed table-striped" style="font-size:11px;white-space:nowrap;margin:0">';
                    html += '<thead><tr><th>#</th><th>退貨日期</th><th>退貨單號</th><th>客戶</th><th>料號</th><th>綁定ID</th><th>品名(左)</th><th>退貨原因IR_ps(右)</th><th>數量</th><th>單價</th><th>ERP備註</th></tr></thead><tbody>';
                    res.preview_rows.forEach(function(r, i) {
                        var boundStyle = r.d_setting_id ? 'color:green' : 'color:#999';
                        var boundVal   = r.d_setting_id ? '✓ ' + r.d_setting_id : '✗';
                        html += '<tr>';
                        html += '<td>' + (i + 1) + '</td>';
                        html += '<td>' + (r.ir_date || '') + '</td>';
                        html += '<td>' + (r.ir_no || '') + '</td>';
                        html += '<td>' + (r.client_name || '') + '</td>';
                        html += '<td>' + (r.d_id || '') + '</td>';
                        html += '<td style="' + boundStyle + '">' + boundVal + '</td>';
                        html += '<td style="color:#666">' + (r.specification || '<em>-</em>') + '</td>';
                        html += '<td><strong>' + (r.ir_ps || '') + '</strong></td>';
                        html += '<td>' + (r.qty || 0) + '</td>';
                        html += '<td>' + (r.unit_price || 0) + '</td>';
                        html += '<td>' + (r.erp_note || '') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div></div>';
                }

                html += '<div class="alert alert-danger" style="font-size:12px;margin-bottom:0">' +
                        '<strong>此操作無法復原！</strong>確認後將立即清除舊資料並匯入新資料。' +
                        '</div>';

                $('#irModalBody').html(html);
                $('#btnIRConfirm').show();
            },
            error: function() {
                $('#irModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
            }
        });
    });

    $('#btnIRConfirm').on('click', function() {
        $(this).prop('disabled', true).text('匯入中...');
        $('#irModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在匯入退貨單資料，請勿關閉視窗...</p>' +
            '</div>'
        );
        $.ajax({
            url: '_upload_For_List.php?but=IR_List_ERP_Commit',
            type: 'POST', dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#irModalBody').html(
                        '<div class="alert alert-success"><strong>匯入成功！</strong><br>' +
                        '清除舊資料：' + res.deleted_rows + ' 筆<br>' +
                        '新增資料：' + res.inserted_rows + ' 筆</div>'
                    );
                    $('#btnIRConfirm').hide();
                    setTimeout(function() { window.location.reload(); }, 2000);
                } else {
                    $('#irModalBody').html('<div class="alert alert-danger"><strong>匯入失敗</strong><br>' + res.message + '</div>');
                    $('#btnIRConfirm').prop('disabled', false).text('確認匯入');
                }
            },
            error: function() {
                $('#irModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
                $('#btnIRConfirm').prop('disabled', false).text('確認匯入');
            }
        });
    });

    // ── BOM ERP直接匯入：兩步驟 AJAX（預覽 → 確認）──────────────────────
    $('#form_bom_erp').on('submit', function(e) {
        e.preventDefault();

        var fileInput = document.getElementById('file_bom_erp');
        if (!fileInput || !fileInput.files.length) {
            alert('請選擇檔案');
            return;
        }

        $('#bomErpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在解析檔案，請稍候...</p>' +
            '</div>'
        );
        $('#btnBomErpConfirm').hide();
        $('#bomErpImportModal').modal('show');

        var fd = new FormData();
        fd.append('file', fileInput.files[0]);

        $.ajax({
            url: '_upload_For_List.php?but=BOM_ERP_Preview',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (!res.success) {
                    $('#bomErpModalBody').html('<div class="alert alert-danger"><strong>無法匯入，驗證失敗</strong><br>' + res.message + '</div>');
                    return;
                }

                var html = '<table class="table table-condensed table-bordered" style="font-size:13px">';
                html += '<tr><td>解析到的 BOM 數</td><td><strong>' + res.bom_count + ' 筆</strong></td></tr>';
                html += '<tr><td>將新增的 BOM</td><td>' + res.new_bom_count + ' 筆</td></tr>';
                html += '<tr><td>將更新的既有 BOM</td><td>' + res.existing_bom_count + ' 筆</td></tr>';
                html += '<tr><td>製程資料列（bom_ing）</td><td>' + res.total_ing_rows + ' 筆</td></tr>';
                html += '</table>';

                if (res.warnings && res.warnings.length) {
                    html += '<div class="alert alert-warning" style="font-size:13px"><strong>⚠ 注意事項</strong><ul style="margin:5px 0 0 0;padding-left:18px">';
                    res.warnings.forEach(function(w) { html += '<li>' + w + '</li>'; });
                    html += '</ul></div>';
                }

                if (res.preview_rows && res.preview_rows.length) {
                    html += '<div class="panel panel-default" style="margin-top:8px">';
                    html += '<div class="panel-heading" style="padding:6px 10px;cursor:pointer;font-size:12px;background:#e3f2fd" onclick="$(this).next().toggle()">' +
                            '▶ DEBUG：前 5 筆 BOM 預覽（點擊展開/收合）</div>';
                    html += '<div class="panel-body" style="padding:5px;display:block;overflow-x:auto">';
                    html += '<table class="table table-condensed table-striped" style="font-size:11px;white-space:nowrap;margin:0">';
                    html += '<thead><tr><th>#</th><th>BOM</th><th>料號</th><th>數量</th><th>客戶</th><th>新/舊</th><th>製程數</th><th>製程</th></tr></thead><tbody>';
                    res.preview_rows.forEach(function(r, i) {
                        html += '<tr>';
                        html += '<td>' + (i + 1) + '</td>';
                        html += '<td>' + (r.bom || '') + '</td>';
                        html += '<td>' + (r.d_id || '') + '</td>';
                        html += '<td>' + (r.sqty || '') + '</td>';
                        html += '<td>' + (r.client_name || '<em style="color:#999">查無</em>') + '</td>';
                        html += '<td>' + (r.is_new ? '<span class="label label-success">新增</span>' : '<span class="label label-default">更新</span>') + '</td>';
                        html += '<td>' + (r.process_count || 0) + '</td>';
                        html += '<td>' + (r.processes || '') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div></div>';
                }

                html += '<div class="alert alert-danger" style="font-size:12px;margin-bottom:0">' +
                        '<strong>此操作無法復原！</strong>確認後將立即寫入/更新 BOM 與製程資料。' +
                        '</div>';

                $('#bomErpModalBody').html(html);
                $('#btnBomErpConfirm').show();
            },
            error: function() {
                $('#bomErpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
            }
        });
    });

    $('#btnBomErpConfirm').on('click', function() {
        $(this).prop('disabled', true).text('匯入中...');
        $('#bomErpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在匯入資料，請勿關閉視窗...</p>' +
            '</div>'
        );

        $.ajax({
            url: '_upload_For_List.php?but=BOM_ERP_Commit',
            type: 'POST',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#bomErpModalBody').html('<div class="alert alert-success"><strong>匯入成功！</strong><br>' + res.message + '</div>');
                    $('#btnBomErpConfirm').hide();
                    setTimeout(function() { window.location.reload(); }, 2000);
                } else {
                    $('#bomErpModalBody').html('<div class="alert alert-danger"><strong>匯入失敗</strong><br>' + res.message + '</div>');
                    $('#btnBomErpConfirm').prop('disabled', false).text('確認匯入');
                }
            },
            error: function() {
                $('#bomErpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
                $('#btnBomErpConfirm').prop('disabled', false).text('確認匯入');
            }
        });
    });

    // ── 移轉紀錄 ERP直接匯入：兩步驟 AJAX（預覽 → 確認）────────────────
    $('#form_transfer_erp').on('submit', function(e) {
        e.preventDefault();

        var fileInput = document.getElementById('file_transfer_erp');
        if (!fileInput || !fileInput.files.length) {
            alert('請選擇檔案');
            return;
        }

        $('#transferErpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在解析檔案，請稍候...</p>' +
            '</div>'
        );
        $('#btnTransferErpConfirm').hide();
        $('#transferErpImportModal').modal('show');

        var fd = new FormData();
        fd.append('file', fileInput.files[0]);

        $.ajax({
            url: '_upload_For_List.php?but=Transfer_ERP_Preview',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (!res.success) {
                    $('#transferErpModalBody').html('<div class="alert alert-danger"><strong>無法匯入，驗證失敗</strong><br>' + res.message + '</div>');
                    return;
                }

                var html = '<table class="table table-condensed table-bordered" style="font-size:13px">';
                html += '<tr><td>有效移轉列數</td><td><strong>' + res.total_rows + ' 筆</strong></td></tr>';
                html += '<tr><td>將新增的製程列</td><td>' + res.insert_count + ' 筆</td></tr>';
                html += '<tr><td>將覆蓋更新</td><td>' + res.write_count + ' 筆</td></tr>';
                if (res.skip_dup_count > 0)   html += '<tr><td>重複跳過(同加工單號)</td><td>' + res.skip_dup_count + ' 筆</td></tr>';
                if (res.skip_stale_count > 0) html += '<tr class="danger"><td>舊檔保護跳過</td><td>' + res.skip_stale_count + ' 筆</td></tr>';
                html += '</table>';

                if (res.warnings && res.warnings.length) {
                    html += '<div class="alert alert-warning" style="font-size:13px"><strong>⚠ 注意事項</strong><ul style="margin:5px 0 0 0;padding-left:18px">';
                    res.warnings.forEach(function(w) { html += '<li>' + w + '</li>'; });
                    html += '</ul></div>';
                }

                if (res.preview_rows && res.preview_rows.length) {
                    html += '<div class="panel panel-default" style="margin-top:8px">';
                    html += '<div class="panel-heading" style="padding:6px 10px;cursor:pointer;font-size:12px;background:#f3e5f5" onclick="$(this).next().toggle()">' +
                            '▶ 前 ' + res.preview_rows.length + ' 筆移轉預覽（點擊展開/收合）</div>';
                    html += '<div class="panel-body" style="padding:5px;display:block;overflow-x:auto">';
                    html += '<table class="table table-condensed table-striped" style="font-size:11px;white-space:nowrap;margin:0">';
                    html += '<thead><tr><th>#</th><th>製令(BOM)</th><th>序號</th><th>製程</th><th>移入廠商</th><th>數量</th><th>移入日</th><th>動作</th></tr></thead><tbody>';
                    res.preview_rows.forEach(function(r, i) {
                        html += '<tr>';
                        html += '<td>' + (i + 1) + '</td>';
                        html += '<td>' + (r.bom || '') + '</td>';
                        html += '<td>' + (r.bom_sn || '') + '</td>';
                        html += '<td>' + (r.process_no || '') + '</td>';
                        html += '<td>' + (r.maker_id_no || '') + (r.maker_name ? '(' + r.maker_name + ')' : '') + '</td>';
                        html += '<td>' + (r.sqty || '') + '</td>';
                        html += '<td>' + (r.move_date || '') + '</td>';
                        var lblCls = {'新增':'label-success','覆蓋更新':'label-primary','重複跳過':'label-default','舊檔跳過':'label-danger'}[r.action] || 'label-default';
                        html += '<td><span class="label ' + lblCls + '">' + (r.action || '') + '</span></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div></div>';
                }

                html += '<div class="alert alert-danger" style="font-size:12px;margin-bottom:0">' +
                        '<strong>此操作無法復原！</strong>確認後將更新製程移轉狀態（狀態改為移轉中、寫入移入日期）。' +
                        '</div>';

                $('#transferErpModalBody').html(html);
                $('#btnTransferErpConfirm').show();
            },
            error: function() {
                $('#transferErpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
            }
        });
    });

    $('#btnTransferErpConfirm').on('click', function() {
        $(this).prop('disabled', true).text('匯入中...');
        $('#transferErpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在匯入資料，請勿關閉視窗...</p>' +
            '</div>'
        );

        $.ajax({
            url: '_upload_For_List.php?but=Transfer_ERP_Commit',
            type: 'POST',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#transferErpModalBody').html('<div class="alert alert-success"><strong>匯入成功！</strong><br>' + res.message + '</div>');
                    $('#btnTransferErpConfirm').hide();
                    setTimeout(function() { window.location.reload(); }, 2000);
                } else {
                    $('#transferErpModalBody').html('<div class="alert alert-danger"><strong>匯入失敗</strong><br>' + res.message + '</div>');
                    $('#btnTransferErpConfirm').prop('disabled', false).text('確認匯入');
                }
            },
            error: function() {
                $('#transferErpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
                $('#btnTransferErpConfirm').prop('disabled', false).text('確認匯入');
            }
        });
    });

    // ── 報價單 ERP直接匯入：兩步驟 AJAX（預覽 → 確認，只新增不覆蓋既有單號）──
    $('#form_quotation_erp').on('submit', function(e) {
        e.preventDefault();

        var fileInput = document.getElementById('file_quotation_erp');
        if (!fileInput || !fileInput.files.length) {
            alert('請選擇檔案');
            return;
        }

        $('#quotationErpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在解析檔案，請稍候...</p>' +
            '</div>'
        );
        $('#btnQuotationErpConfirm').hide();
        $('#quotationErpImportModal').modal('show');

        var fd = new FormData();
        fd.append('file', fileInput.files[0]);

        $.ajax({
            url: '_upload_For_List.php?but=Quotation_ERP_Preview',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (!res.success) {
                    var errHtml = '<div class="alert alert-danger"><strong>無法匯入，驗證失敗</strong>';
                    if (res.errors && res.errors.length) {
                        errHtml += '<ul style="margin:8px 0 0 0;padding-left:18px">';
                        res.errors.forEach(function(e) { errHtml += '<li>' + e + '</li>'; });
                        errHtml += '</ul>';
                    } else {
                        errHtml += '<br>' + res.message;
                    }
                    errHtml += '</div>';
                    $('#quotationErpModalBody').html(errHtml);
                    return;
                }

                var html = '<table class="table table-condensed table-bordered" style="font-size:13px">';
                html += '<tr><td>解析到的報價單數</td><td><strong>' + res.total_quote_count + ' 張</strong></td></tr>';
                html += '<tr><td>資料日期範圍</td><td>' + (res.date_min || '') + ' ～ ' + (res.date_max || '') + '</td></tr>';
                html += '<tr class="success"><td>將新增的報價單</td><td><strong>' + res.new_quote_count + ' 張</strong>（共 ' + res.total_item_rows + ' 筆明細）</td></tr>';
                if (res.skip_quote_count > 0) {
                    html += '<tr class="warning"><td>已存在，將跳過不動</td><td><strong>' + res.skip_quote_count + ' 張</strong>' +
                            (res.skip_sample && res.skip_sample.length ? '（例：' + res.skip_sample.join('、') + (res.skip_quote_count > res.skip_sample.length ? '…' : '') + '）' : '') + '</td></tr>';
                }
                html += '</table>';

                if (res.warnings && res.warnings.length) {
                    html += '<div class="alert alert-warning" style="font-size:13px"><strong>⚠ 注意事項</strong><ul style="margin:5px 0 0 0;padding-left:18px">';
                    res.warnings.forEach(function(w) { html += '<li>' + w + '</li>'; });
                    html += '</ul></div>';
                }

                if (res.preview_rows && res.preview_rows.length) {
                    html += '<div class="panel panel-default" style="margin-top:8px">';
                    html += '<div class="panel-heading" style="padding:6px 10px;cursor:pointer;font-size:12px;background:#F7E0BD" onclick="$(this).next().toggle()">' +
                            '▶ DEBUG：前 5 張新增報價單預覽（點擊展開/收合）</div>';
                    html += '<div class="panel-body" style="padding:5px;display:block;overflow-x:auto">';
                    html += '<table class="table table-condensed table-striped" style="font-size:11px;white-space:nowrap;margin:0">';
                    html += '<thead><tr><th>#</th><th>單據日期</th><th>報價單號</th><th>客戶代碼</th><th>客戶名稱</th><th>明細筆數</th><th>首筆料號</th></tr></thead><tbody>';
                    res.preview_rows.forEach(function(r, i) {
                        html += '<tr>';
                        html += '<td>' + (i + 1) + '</td>';
                        html += '<td>' + (r.quote_date || '') + '</td>';
                        html += '<td>' + (r.quote_no || '') + '</td>';
                        html += '<td>' + (r.client_id || '<em style="color:#999">查無</em>') + '</td>';
                        html += '<td>' + (r.client_name || '<em style="color:#999">查無</em>') + '</td>';
                        html += '<td>' + (r.item_count || 0) + '</td>';
                        html += '<td>' + (r.first_item || '') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div></div>';
                }

                html += '<div class="alert alert-danger" style="font-size:12px;margin-bottom:0">' +
                        '<strong>此操作僅會新增資料，不會刪除或修改任何既有報價單。</strong>確認後將立即寫入新報價單。' +
                        '</div>';

                $('#quotationErpModalBody').html(html);
                $('#btnQuotationErpConfirm').show();
            },
            error: function() {
                $('#quotationErpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
            }
        });
    });

    $('#btnQuotationErpConfirm').on('click', function() {
        $(this).prop('disabled', true).text('匯入中...');
        $('#quotationErpModalBody').html(
            '<div class="text-center" style="padding:20px">' +
            '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
            '<p style="margin-top:15px">正在匯入資料，請勿關閉視窗...</p>' +
            '</div>'
        );

        $.ajax({
            url: '_upload_For_List.php?but=Quotation_ERP_Commit',
            type: 'POST',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#quotationErpModalBody').html('<div class="alert alert-success"><strong>匯入成功！</strong><br>' + res.message + '</div>');
                    $('#btnQuotationErpConfirm').hide();
                    setTimeout(function() { window.location.reload(); }, 2000);
                } else {
                    $('#quotationErpModalBody').html('<div class="alert alert-danger"><strong>匯入失敗</strong><br>' + res.message + '</div>');
                    $('#btnQuotationErpConfirm').prop('disabled', false).text('確認匯入');
                }
            },
            error: function() {
                $('#quotationErpModalBody').html('<div class="alert alert-danger">伺服器錯誤，請稍後再試</div>');
                $('#btnQuotationErpConfirm').prop('disabled', false).text('確認匯入');
            }
        });
    });
});
</script>

<!-- 移轉紀錄 ERP匯入預覽 Modal -->
<div class="modal fade" id="transferErpImportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document" style="width:640px">
        <div class="modal-content">
            <div class="modal-header" style="background:#6a1b9a;color:#fff;padding:12px 15px">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1">&times;</button>
                <h4 class="modal-title"><strong>移轉紀錄 ERP直接匯入確認</strong></h4>
            </div>
            <div class="modal-body" id="transferErpModalBody" style="padding:15px;max-height:70vh;overflow-y:auto">
            </div>
            <div class="modal-footer" style="padding:10px 15px">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btnTransferErpConfirm" style="display:none">確認匯入</button>
            </div>
        </div>
    </div>
</div>

<!-- BOM ERP匯入預覽 Modal -->
<div class="modal fade" id="bomErpImportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document" style="width:640px">
        <div class="modal-content">
            <div class="modal-header" style="background:#1565c0;color:#fff;padding:12px 15px">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1">&times;</button>
                <h4 class="modal-title"><strong>BOM ERP直接匯入確認</strong></h4>
            </div>
            <div class="modal-body" id="bomErpModalBody" style="padding:15px;max-height:70vh;overflow-y:auto">
            </div>
            <div class="modal-footer" style="padding:10px 15px">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btnBomErpConfirm" style="display:none">確認匯入</button>
            </div>
        </div>
    </div>
</div>

<!-- ERP 匯入預覽 Modal -->
<div class="modal fade" id="erpImportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document" style="width:560px">
        <div class="modal-content">
            <div class="modal-header" style="background:#f5a623;color:#fff;padding:12px 15px">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1">&times;</button>
                <h4 class="modal-title"><strong>ERP 出貨單匯入確認</strong></h4>
            </div>
            <div class="modal-body" id="erpModalBody" style="padding:15px">
            </div>
            <div class="modal-footer" style="padding:10px 15px">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btnERPConfirm" style="display:none">確認匯入</button>
            </div>
        </div>
    </div>
</div>

<!-- IR 退貨單匯入預覽 Modal -->
<div class="modal fade" id="irImportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document" style="width:640px">
        <div class="modal-content">
            <div class="modal-header" style="background:#c0392b;color:#fff;padding:12px 15px">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1">&times;</button>
                <h4 class="modal-title"><strong>ERP 退貨單匯入確認</strong></h4>
            </div>
            <div class="modal-body" id="irModalBody" style="padding:15px;max-height:70vh;overflow-y:auto">
            </div>
            <div class="modal-footer" style="padding:10px 15px">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btnIRConfirm" style="display:none">確認匯入</button>
            </div>
        </div>
    </div>
</div>

<!-- 報價單 ERP匯入預覽 Modal -->
<div class="modal fade" id="quotationErpImportModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document" style="width:640px">
        <div class="modal-content">
            <div class="modal-header" style="background:#8a5a2b;color:#fff;padding:12px 15px">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1">&times;</button>
                <h4 class="modal-title"><strong>報價單 ERP直接匯入確認</strong></h4>
            </div>
            <div class="modal-body" id="quotationErpModalBody" style="padding:15px;max-height:70vh;overflow-y:auto">
            </div>
            <div class="modal-footer" style="padding:10px 15px">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btnQuotationErpConfirm" style="display:none">確認匯入</button>
            </div>
        </div>
    </div>
</div>

<?php if ($canClearIsList): ?>
<div class="modal fade" id="clearIsListModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document" style="width:480px">
        <div class="modal-content">
            <div class="modal-header" style="background:#DD5138;color:#fff;padding:12px 15px">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1">&times;</button>
                <h4 class="modal-title"><strong>清除出貨單資料確認</strong></h4>
            </div>
            <div class="modal-body" style="padding:15px">
                <div style="margin-bottom:10px">
                    <label style="display:block;font-weight:normal;margin-bottom:4px">
                        <input type="radio" name="isClearMode" value="month" checked> 依月份清除（該年月起之後全部清除）
                    </label>
                    <label style="display:block;font-weight:normal;margin-bottom:4px">
                        <input type="radio" name="isClearMode" value="year"> 依年度清除（該年度起之後全部清除）
                    </label>
                    <label style="display:block;font-weight:normal">
                        <input type="radio" name="isClearMode" value="year_only"> 清除整個年度（僅該年度，不影響其他年度）
                    </label>
                </div>
                <div style="margin-bottom:10px">
                    <select id="isClearYear" class="form-control" style="width:110px;display:inline-block"></select>
                    <select id="isClearMonth" class="form-control" style="width:90px;display:inline-block;margin-left:6px"></select>
                </div>
                <p id="clearIsListMsg" style="color:#5b3a1e"></p>
                <p id="clearIsListCount" style="font-weight:bold"></p>
                <input type="password" id="clearIsListPwd" autocomplete="new-password" placeholder="請輸入操作確認密碼" class="form-control">
                <div id="clearIsListErr" style="color:#DD5138;margin-top:6px;font-size:12px;"></div>
            </div>
            <div class="modal-footer" style="padding:10px 15px">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="btnClearIsListConfirm">確認清除</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
