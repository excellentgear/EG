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


@$userName      = $_SESSION['user_cname'];
@$id            = $_SESSION['id'];

// 查詢各匯入功能最近上傳紀錄（由 system_settings 記錄）
$uploadLogs = [];
try {
    $r = $db->query("SELECT setting_key, updated_at AS ts, updated_by AS creator
                     FROM system_settings
                     WHERE setting_key IN (
                         'upload_bom_nb','upload_bom_ing_new','upload_bom_ing_s',
                         'upload_bom_nb_ok','upload_transfer_log','upload_transfer_log_raw',
                         'upload_is_erp','upload_ir_erp','upload_bom_erp','upload_bom_ing_s_erp'
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

function lastUpdateBadge($info, $color = '#555') {
    if (!$info || empty($info['ts'])) return '<span style="font-size:11px;color:#aaa">尚無記錄</span>';
    $ts      = date('Y-m-d H:i', strtotime($info['ts']));
    $creator = htmlspecialchars($info['creator'] ?? '');
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
                                    
                                    <!-- 上傳-訂單未交 (已隱藏) -->
                                    <form action="_upload_For_List.php?but=Order" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file">訂單未交(order-OK)<small>(只接受.xls)</small><BR> <span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8"> <!-- 修改這行的 col-md 和 col-sm -->
                                                <div class="input-group">
                                                    <input type="file" id="file_order_list" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_order_list" class="btn btn-success">上傳</button>
                                                        
                                                    </span>
                                                </div><h2 style="color:red">訂單未交 會自動刪除舊未交</h2>
                                            </div>
                                        </div>
                                    </form>
                                    
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

                                    <!-- 上傳-新bom(新增bom) 2025.02.27 ok-->
                                    <form action="_upload_For_List.php?but=nb" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
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
                                    <form action="_upload_For_List.php?but=u5_NEW" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
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

                                    <!-- 上傳-移轉紀錄(新增S) 2025.02 ok-->
                                    <form action="_upload_For_List.php?but=u5" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
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
                                                <span class="text-muted" style="font-size:11px;">自動偵測民國/西元年<br>找最早日期起清空後重匯</span>
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

                                    <!-- 上傳-退貨單 ERP直接匯入 (ir_track) -->
                                    <div style="background:#fce4ec;border:2px solid #f48fb1;border-radius:6px;padding:8px 12px;margin-bottom:10px">
                                    <form id="form_ir_list_erp" action="_upload_For_List.php?but=IR_List_ERP" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
                                        <div class="item form-group" style="margin-bottom:0">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_ir_list_erp">
                                                退貨單 <b>ERP直接匯入</b><small>(只接受.xls/.xlsx)</small><br>
                                                <span class="required">*</span>
                                                <span class="text-muted" style="font-size:11px;">確認「銷貨退回日報表」+「IR」開頭<br>找最早日期起清空後重匯</span>
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

                                    <!-- 上傳-出貨單 (is_list) — 已由 ERP直接匯入 取代，暫時隱藏 -->
                                    <form action="_upload_For_List.php?but=IS_List" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate style="display:none">
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="file_is_list">出貨單 (is_list)<small>(只接受.xls/.xlsx)</small><br><span class="required">*</span></label>
                                            <div class="col-md-4 col-sm-4 col-xs-8">
                                                <div class="input-group">
                                                    <input type="file" id="file_is_list" name="file" accept=".xls,.xlsx" class="form-control short-input">
                                                    <span class="input-group-btn">
                                                        <button type="submit" id="btn_upload_is_list" class="btn btn-success">上傳</button>
                                                        <button type="button" id="btn_clear_is_list" class="btn btn-danger" style="margin-left: 5px;">清除</button>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </form>

                                    <!-- 上傳-加工單價 (transfer_log) -->
                                    <form action="_upload_For_List.php?but=transfer_log" method="post" enctype="multipart/form-data" class="form-horizontal form-label-left" novalidate>
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
                                                <span class="text-muted" style="font-size:11px;">直接上傳 ERP 移轉紀錄原始檔<br>免 VBA 轉檔，民國年自動轉西元</span>
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
        { inputId: 'file_order_list', buttonId: 'btn_upload_order_list' },
        { inputId: 'file_new_bom', buttonId: 'btn_upload_new_bom' },
        { inputId: 'file_bom_ing_ok', buttonId: 'btn_upload_bom_ing_ok' },
        { inputId: 'file_transfer_record', buttonId: 'btn_upload_transfer_record' },
        { inputId: 'file_bom_closeout', buttonId: 'btn_upload_bom_closeout' },
        { inputId: 'file_reply_tg', buttonId: 'btn_upload_reply_tg' },
        { inputId: 'file_is_list_erp', buttonId: 'btn_upload_is_list_erp' },
        { inputId: 'file_ir_list_erp', buttonId: 'btn_upload_ir_list_erp' },
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

    // Logic for the new "Clear IS_List" button
    const clearButton = document.getElementById('btn_clear_is_list');
    if (clearButton) {
        const today = new Date();
        let startMonth = today.getMonth() + 1; // getMonth() is 0-indexed, so +1

        // 修改：更新按鈕文字為 "清除 X 月後資料"
        clearButton.textContent = `清除${startMonth}月後資料`;

        clearButton.addEventListener('click', function() {
            // 修改：更新確認訊息
            if (confirm(`您確定要清除 ${startMonth}月 之後的所有出貨單記錄嗎？此操作無法復原。`)) {
                // AJAX call to the backend script
                $.ajax({
                    url: '../../src/store/_clear_is_list.php', // The new backend script
                    type: 'POST',
                    data: {
                        start_month: startMonth,
                        // 移除 end_month 參數
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert('成功清除 ' + response.deleted_rows + ' 筆記錄。');
                            // Optionally reload the page or update the UI
                            window.location.reload();
                        } else {
                            alert('清除失敗：' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('清除操作失敗，請檢查伺服器連線。');
                        console.error("Clear IS_List Error:", status, error, xhr.responseText);
                    }
                });
            }
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
                html += '<tr class="danger"><td>將清除的舊資料</td><td><strong>' + res.existing_delete_count + ' 筆</strong>（' + res.date_min + ' 起含當日）</td></tr>';
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
                html += '<tr class="danger"><td>將清除的舊資料</td><td><strong>' + res.existing_delete_count + ' 筆</strong>（' + res.date_min + ' 起含當日）</td></tr>';
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

</body>
</html>
