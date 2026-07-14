<?php

session_start();
if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage'] = "../../views/pm/schedule_T5.php?b=" . $_GET['b'];
    header("Location:../../index.php?b=" . $_GET['b']); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$user_status   = $_SESSION['status'];

$msg = null;
@$id = $_GET['id'];
@$new = $_GET['new'];

//TG
$conn = new DBConnection();

if (isset($_GET['pti']) && is_numeric($_GET['pti'])) {
    $pti_val = intval($_GET['pti']);
    if ($pti_val == 1) {
        $pti = 1;
        $pti_m = 1;
        $maker_check_name = '原一';
    } else {
        $pti = $pti_val;
        $pti_m = $pti_val;
        $maker_check_name = '超正';
    }
} else {
    // Default case if pti is not set or not numeric
    $pti = 12;
    $pti_m = 12;
    $maker_check_name = '超正';
}

// Construct the view name safely
$vw_pti = 'vw_pti_' . $pti . '_list';

$tg_list = $conn->getAll("SELECT DISTINCT machine,`machine_id`
from $vw_pti
ORDER BY `machine_id`");

// 機台
@$machine_id_list = $conn->getAll("SELECT `machine_id`,`machine` FROM machine_list WHERE `machine_type_id`=$pti_m ORDER BY `machine_id`");

// 從 session 獲取當前登入者的 ID，更安全可靠
$current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;

// 查詢權限並從結果陣列中取出單一值。將 ID 轉為整數可防止 SQL 注入。
$user_ch_result = $conn->getAll("SELECT `schedule_change` FROM `user_permissions` WHERE `user_id` = $current_user_id");
@$user_ch = !empty($user_ch_result) ? $user_ch_result[0]['schedule_change'] : 0;

$user_pm_result = $conn->getAll("SELECT `schedule_pm` FROM `user_permissions` WHERE `user_id` = $current_user_id");
@$user_pm = !empty($user_pm_result) ? $user_pm_result[0]['schedule_pm'] : 0;

//準備更新TG
@$bom_ing_fid = null;
@$bom_ing_fid = $_GET['bom_ing_fid'];
@$mi = $_GET['mi'];

if ($bom_ing_fid != null) {
    // The view name $vw_pti is already sanitized.
    // Use a prepared statement to prevent SQL injection for bom_ing_fid.
    $sql = "SELECT * FROM " . $vw_pti . " WHERE bom_ing_fid = :bom_ing_fid";
    $cmd = $db->prepare($sql);
    $cmd->bindParam(':bom_ing_fid', $bom_ing_fid, PDO::PARAM_INT);
    $cmd->execute();
    $row = $cmd->fetch();

    @$specification      = $row['specification'];
    @$machine_id         = $row['machine_id'];
    @$machine            = $row['machine'];
    @$processing_sequence = $row['processing_sequence'];
    @$bom                = $row['bom'];
    @$bom_ing_id         = $row['bom_ing_id'];
    @$sqty               = $row['sqty'];
    @$outsource_date     = $row['outsource_date'];
    @$d_id               = $row['d_id'];
    @$ps                 = $row['ps'];
    @$Delivery_date      = $row['order_Delivery_date'];
    @$PS2                = $row['PS2'];
    if ($pti == 1) {
        @$pti01_ps           = $row['pti01_ps'];
    };
    @$Client_Name        = $row['Client_Name'];
    @$single_bet_ps      = $row['single_bet_ps'];
};

@$pmi = $_GET['pmi'];



?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Excellentgear 超正齒輪</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
</head>
<style>
    .button-container {
        display: flex;
        flex-wrap: wrap;
    }

    .button-container form {
        margin-right: 5px;
        margin-bottom: 5px;
    }

    .table-responsive {
        overflow-x: auto;
    }

    /* 
    .table {
        width: 100%;
        max-width: 100%;
    } */

    .table th,
    .table td {
        white-space: nowrap;
        text-align: left;
    }

    .table td {
        vertical-align: middle;
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
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        transition: all 0.3s;
        z-index: 1000;
    }

    .scroll-to-top:hover {
        background-color: rgba(255, 255, 255, 0.7);
    }

    .btn-copy {
        background-color: #f0ad4e;
        /* Yellow background */
        color: white;
        /* White icon/text */
        border: none;
        margin-right: 5px;
        padding: 1px 5px;
        vertical-align: middle;
        cursor: pointer;
    }

    /* 讓表格更緊湊，並確保內容不換行 */
    .table-compact th,
    .table-compact td {
        padding: 5px 8px;
        /* 減少垂直和水平邊距 */
    }
</style>

<script>
    // 在頁面加載時恢復滾動位置
    window.onload = function() {
        if (sessionStorage.scrollPosition) {
            window.scrollTo(0, sessionStorage.scrollPosition);
        }
        if (sessionStorage.highlightedRow) {
            var row = document.querySelector('tr[data-row-id="' + sessionStorage.highlightedRow + '"]');
            if (row) {
                row.classList.add('highlighted-row');
            }
        }
    };

    // 在頁面卸載時保存滾動位置
    window.onbeforeunload = function() {
        sessionStorage.scrollPosition = window.scrollY;
    };

    function highlightRow(rowId) {
        sessionStorage.highlightedRow = rowId;
    }

    // 回到頂端功能
    function scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    // 按下更新或排機台自動上移至頂端
    function submitFormAndScrollToTop() {
        // The surrounding <a> tag handles navigation.
        // This function just needs to scroll to the top.
        // The onbeforeunload handler will then save the scroll position as 0.
        window.scrollTo(0, 0);
    }

    // 確保highlightRow函數不影響表單提交-test
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(event) {
            console.log("Submitting form to: ", form.action);
            highlightRow(this.querySelector('input[name="id"]').value);
        });
    });

    window.onload = function() {
        if (sessionStorage.scrollPosition) {
            window.scrollTo(0, sessionStorage.scrollPosition);
            console.log("Restored scroll position: ", sessionStorage.scrollPosition);
        }
        if (sessionStorage.highlightedRow) {
            var row = document.querySelector('tr[data-row-id="' + sessionStorage.highlightedRow + '"]');
            if (row) {
                row.classList.add('highlighted-row');
                console.log("Highlighted row: ", sessionStorage.highlightedRow);
            }
        }
    };

    window.onbeforeunload = function() {
        sessionStorage.scrollPosition = window.scrollY;
        console.log("Saved scroll position: ", window.scrollY);
    };

    function highlightRow(rowId) {
        sessionStorage.highlightedRow = rowId;
        console.log("Highlighting row: ", rowId);
    }

    // 在表單提交後立即禁用按鈕，防止多次提交。這樣可以確保每次點擊只會觸發一次提交。
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(event) {
            const submitButton = this.querySelector('input[type="submit"]');
            submitButton.disabled = true;
            console.log("Submitting form to: ", form.action);
            highlightRow(this.querySelector('input[name="id"]').value);
        });
    });

    // 減少頁面刷新次數
    // 嘗試避免頁面過於頻繁地刷新，以防止狀態變化引起的問題。使用 AJAX 提交表單數據並處理響應，而不是完全刷新頁面。
    function submitFormAndScrollToTop(event, form) {
        event.preventDefault(); // 防止默認提交
        const submitButton = form.querySelector('input[type="submit"]');
        submitButton.disabled = true;
        const formData = new FormData(form);

        fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                console.log("Form submitted and response received: ", data);
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
                // 在這裡可以更新頁面內容，而不需要完全刷新頁面
            })
            .catch(error => {
                console.error("Error submitting form: ", error);
            })
            .finally(() => {
                submitButton.disabled = false;
            });
    }

    // 綁定事件
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(event) {
            submitFormAndScrollToTop(event, form);
        });
    });

    // 重置和刷新
    // 如果在交換過程中發現卡住，嘗試進行頁面刷新以重新載入狀態。這可以通過JavaScript來實現：
    function refreshPage() {
        window.location.reload();
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
            <button class="scroll-to-top" onclick="scrollToTop()">回頂端</button>
            <!-- page content -->
            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title">
                            <!-- <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&new=1"><input type="button" class="btn btn-xs btn-success" value="新增"></a> -->
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=1"><input type="button" class="btn btn-xs btn-primary" value="車床"></a>
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=2"><input type="button" class="btn btn-xs btn-primary" value="銑床"></a>
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=4"><input type="button" class="btn btn-xs btn-primary" value="滾齒"></a>
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=33"><input type="button" class="btn btn-xs btn-primary" value="平研"></a>
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=11"><input type="button" class="btn btn-xs btn-primary" value="外研"></a>
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=12"><input type="button" class="btn btn-xs btn-primary" value="齒研"></a>
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=189"><input type="button" class="btn btn-xs btn-primary" value="其他製程"></a>
                            <a href="../../views/pm/schedule_T5.php?id=<?= $_GET['id'] ?>&pti=16"><input type="button" class="btn btn-xs btn-primary" value="雷刻與包裝"></a>
                        </div>
                        <div class="title_left">
                            <h4>
                                <?php
                                if (!empty($_GET['message'])) {
                                    if ($_GET['message'] == "success") {
                                        echo "<div id=\"message\" class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    新增成功
                                    </div>";
                                    } else if ($_GET['message'] != "success") {
                                        $var = $_GET['message'];
                                        echo "<div id=\"message\" class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    $var
                                    </div>";
                                    }
                                }
                                ?>
                            </h4>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <?PHP if ($bom_ing_fid != null or $new != null) { ?>
                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">

                                    <div class="x_title">
                                        <h2><?= $msg ?></h2>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                            </li>
                                            <li><a class="close-link"><i class="fa fa-close"></i></a>
                                            </li>
                                        </ul>
                                        <div class="clearfix">
                                            <?PHP if ($new == 1) { ?>
                                                <h2>新增待加工<SMALL>(同步新增至BOM)</SMALL></h2>
                                            <?php } else { ?>
                                                <h2>資料修改</h2>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <div class="x_content">

                                        <form method="POST" action="../../src/store/_updateT5.php?mi=<?= $mi ?>&pmi=<?= $pmi ?>&pti=<?= $pti ?>&bom_ing_fid=<?= $bom_ing_fid ?>&id=<?= $id ?>" class="form-horizontal form-label-left" novalidate>
                                            <span class="section"></span>
                                            <input type="hidden" id="bom_ing_fid" class="form-control col-md-3 col-xs-3" value="<?= $bom_ing_fid ?>" type="text">
                                            <input type="hidden" id="bom_ing_id" class="form-control col-md-3 col-xs-3" value="<?= $bom_ing_id ?>" type="text">

                                            <?PHP if (@$user_ch == 1) { ?>
                                                <!-- <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="processing_sequence">順序 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-3">
                                                    <input id="processing_sequence" class="form-control col-md-7 col-xs-12" value="<?= $processing_sequence ?>" data-validate-length-range="6" data-validate-words="1" name="processing_sequence" placeholder="" required="required" type="text">
                                                </div>
                                            </div> -->

                                                <!-- <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Delivery_date">訂單交期 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-4">
                                                    <input id="Delivery_date" class="form-control col-md-7 col-xs-12" value="<?= $Delivery_date ?>" data-validate-length-range="6" data-validate-words="1" name="Delivery_date" required="required" type="text">
                                                </div>
                                            </div> -->

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name">客戶 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <input id="Client_Name" readonly class="form-control col-md-7 col-xs-12" value="<?= $Client_Name ?>" data-validate-length-range="2" data-validate-words="1" name="Client_Name" required="required" type="text">
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="bom">BOM <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-4">
                                                        <?PHP if ($new == 1) { ?>
                                                            <input id="bom" class="form-control col-md-7 col-xs-12" value="<?= $bom ?>" data-validate-length-range="6" data-validate-words="1" name="bom" required="required" type="text">
                                                        <?php } else { ?>
                                                            <input id="bom" class="form-control col-md-7 col-xs-12" READONLY value="<?= $bom ?>" data-validate-length-range="6" data-validate-words="1" name="bom" required="required" type="text">
                                                        <?php } ?>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="d_id">產編 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-6">
                                                        <?PHP if ($new == 1) { ?>
                                                            <input id="d_id" class="form-control col-md-7 col-xs-12" value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                        <?php } else { ?>
                                                            <input id="d_id" class="form-control col-md-7 col-xs-12" READONLY value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="machine_id"> 機台 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <select name="machine_id" id="machine_id">
                                                            <?php if (!isset($mi) || $mi == 0) { ?>
                                                                <option value="0" selected>請選擇</option>
                                                            <?php } ?>
                                                            <?php foreach ($machine_id_list as $machine_item) { ?>
                                                                <option value="<?= $machine_item['machine_id'] ?>" <?= (isset($mi) && $mi == $machine_item['machine_id']) ? 'selected' : '' ?>>
                                                                    <?= $machine_item['machine'] ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="PS2">現場備註 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-4">
                                                        <input id="PS2" class="form-control col-md-7 col-xs-12" value="<?= $PS2 ?>" data-validate-length-range="6" data-validate-words="1" name="PS2" required="required" type="text">
                                                    </div>
                                                </div>
                                            <?php } else { ?>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="processing_sequence">順序 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <input id="processing_sequence" class="form-control col-md-7 col-xs-12" value="<?= $processing_sequence ?>" data-validate-length-range="6" data-validate-words="1" name="processing_sequence" placeholder="" required="required" type="text">
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="machine_id"> 機台 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <select name="machine_id" id="machine_id" class="form-control">
                                                            <?php if (!isset($mi) || $mi == 0) { ?>
                                                                <option value="0" selected>請選擇</option>
                                                            <?php } ?>
                                                            <?php foreach ($machine_id_list as $machine_item) { ?>
                                                                <option value="<?= $machine_item['machine_id'] ?>" <?= (isset($mi) && $mi == $machine_item['machine_id']) ? 'selected' : '' ?>>
                                                                    <?= $machine_item['machine'] ?>
                                                                </option>
                                                            <?php } ?>

                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name">客戶 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <input id="Client_Name" calss="form-control col-md-7 col-xs-12" value="<?= $Client_Name ?>" data-validate-length-range="2" data-validate-words="1" name="Client_Name" required="required" type="text">
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="bom">BOM <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-4">
                                                        <?PHP if ($new == 1) { ?>
                                                            <input id="bom" class="form-control col-md-7 col-xs-12" value="<?= $bom ?>" data-validate-length-range="6" data-validate-words="1" name="bom" required="required" type="text">
                                                        <?php } else { ?>
                                                            <input id="bom" class="form-control col-md-7 col-xs-12" READONLY value="<?= $bom ?>" data-validate-length-range="6" data-validate-words="1" name="bom" required="required" type="text">
                                                        <?php } ?>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="d_id">產編 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-6">
                                                        <?PHP if ($new == 1) { ?>
                                                            <input id="d_id" class="form-control col-md-7 col-xs-12" value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                        <?php } else { ?>
                                                            <input id="d_id" class="form-control col-md-7 col-xs-12" READONLY value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                        <?php } ?>
                                                    </div>
                                                </div>

                                                <?php if ($pti_m == 12 or $pti_m == 4) { ?>
                                                    <div class="item form-group">
                                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="specification">規格<span class="required">*</span>
                                                        </label>
                                                        <div class="col-md-2 col-sm-2 col-xs-3">
                                                            <?PHP if ($new == 1) { ?>
                                                                <input id="specification" class="form-control col-md-7 col-xs-12" value="<?= $specification ?>" data-validate-length-range="6" data-validate-words="1" name="specification" required="required" type="text">
                                                            <?php } else { ?>
                                                                <input id="specification" class="form-control col-md-7 col-xs-12" READONLY value="<?= $specification ?>" data-validate-length-range="6" data-validate-words="1" name="specification" required="required" type="text">
                                                            <?php } ?>
                                                        </div>
                                                    </div>
                                                    <div class="item form-group">
                                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="single_bet_ps">單關備註 <span class="required"></span>
                                                        </label>
                                                        <div class="col-md-4 col-sm-4 col-xs-8">
                                                            <input id="single_bet_ps" class="form-control col-md-10 col-xs-12" value="<?= $single_bet_ps ?>" name="single_bet_ps" type="text">
                                                        </div>
                                                    </div>
                                                <?php } elseif ($pti_m == 1) { ?>
                                                    <div class="item form-group">
                                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="pti01_ps">叫料/發圖 <span class="required">*</span>
                                                        </label>
                                                        <div class="col-md-2 col-sm-2 col-xs-4">
                                                            <input id="pti01_ps" class="form-control col-md-7 col-xs-12" value="<?= $pti01_ps ?>" data-validate-length-range="6" data-validate-words="1" name="pti01_ps" required="required" type="text">
                                                        </div>
                                                    </div>
                                                    <div class="item form-group">
                                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="single_bet_ps">單關備註 <span class="required"></span>
                                                        </label>
                                                        <div class="col-md-4 col-sm-4 col-xs-8">
                                                            <input id="single_bet_ps" class="form-control col-md-10 col-xs-12" value="<?= $single_bet_ps ?>" name="single_bet_ps" type="text">
                                                        </div>
                                                    </div>
                                                <?php } else { ?>
                                                    
                                                    <div class="item form-group">
                                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="single_bet_ps">單關備註 <span class="required"></span>
                                                        </label>
                                                        <div class="col-md-4 col-sm-4 col-xs-8">
                                                            <input id="single_bet_ps" class="form-control col-md-10 col-xs-12" value="<?= $single_bet_ps ?>" name="single_bet_ps" type="text">
                                                        </div>
                                                    </div>
                                                <?php } ?>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="sqty">數量 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <input id="sqty" readonly class="form-control col-md-7 col-xs-12" value="<?= $sqty ?>" data-validate-length-range="6" data-validate-words="1" name="sqty" required="required" type="text">
                                                    </div>
                                                </div>

                                                <?PHP if ($new == 1) { ?>
                                                <?php } else { ?>
                                                    <div class="item form-group">
                                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="outsource_date">發包日期 <span class="required"></span>
                                                        </label>
                                                        <div class="col-md-2 col-sm-2 col-xs-3">
                                                            <input id="outsource_date" class="form-control col-md-7 col-xs-12" READONLY value="<?= $outsource_date ?>" data-validate-length-range="2" data-validate-words="1" name="outsource_date" required="required" type="text">
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                                <!-- 
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ps">ERP備註 <span class="required"></span>
                                                    </label>
                                                    <div class="col-md-4 col-sm-4 col-xs-8">
                                                        <input id="ps" class="form-control col-md-10 col-xs-12" value="<?= $ps ?>" name="ps" required="required" type="text">
                                                    </div>
                                                </div> -->
                                            <?php } ?>

                                            <div class="ln_solid"></div>
                                            <div class="form-group">
                                                <div class="col-md-6 col-md-offset-3">
                                                    <?PHP if ($new == 1) { ?>
                                                        <a href="../../views/pm/schedule_T5.php?pti=<?= $pti ?>&id=<?= $_GET['id'] ?>"><input type="button" class="btn btn-primary" value="取消 / 清除"></a>
                                                        <button onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>) id=" send" name="newT5" type="submit" class="btn btn-success">送出</button>
                                                    <?php } else { ?>
                                                        <a href="../../views/pm/schedule_T5.php?pti=<?= $pti ?>&id=<?= $_GET['id'] ?>"><input type="button" class="btn btn-primary" value="取消 / 清除"></a>
                                                        <button onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>) id=" send" name="updataT5" type="submit" class="btn btn-success">送出</button>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </form>
                                    </div>

                                </div>
                            </div>
                        </div>
                    <?PHP } ?>
                    <!-- <div class="clearfix"></div> -->
                    <!-- T5 -->


                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <?php if (isset($pti)) {
                                            $conn = new DBConnection();
                                            $pti_one = $conn->getAll("SELECT `process_type_id`,`process_type` FROM process_type 
                                                                        WHERE `process_type_id`=$pti");
                                            foreach ($pti_one as $pti_one) { ?>
                                                <h2><?= $pti_one['process_type'] ?> 排程</h2>
                                        <?php }
                                        } ?>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                        </ul>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">
                                        <p class="text-muted font-13 m-b-30"></p>

                                        <?php
                                        $conn = new DBConnection();
                                        $processStmt = $db->prepare("SELECT ProcessNo, process_type_id FROM process_no");
                                        $processStmt->execute();
                                        $processTypes = $processStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                                        if ($pti == 1) {
                                            $ALL_LIST = $conn->getAll("
                                                                            SELECT d_id, bom_ing_fid, Client_Name, bom_ing_id, bom, machine_id, process_no,
                                                                                   maker_id, sqty, processing_sequence, ProcessName, processing_state, ps,
                                                                                   outsource_date, return_date, Created_By, order_Delivery_date as Delivery_date, PS2, pti01_ps, machine, single_bet_ps
                                                                              FROM `$vw_pti`
                                                                             ORDER BY machine_id ASC, CAST(processing_sequence AS UNSIGNED) ASC
                                                                        ");
                                        } else {
                                            $ALL_LIST = $conn->getAll("
                                                                        SELECT d_id, bom_ing_fid, Client_Name, bom_ing_id, bom, machine_id, process_no,
                                                                               maker_id, sqty, processing_sequence, ProcessName, processing_state, ps,
                                                                               outsource_date, return_date, Created_By, order_Delivery_date as Delivery_date, PS2, machine, single_bet_ps
                                                                          FROM `$vw_pti`
                                                                        ORDER BY machine_id ASC, CAST(processing_sequence AS UNSIGNED) ASC
                                                                    ");
                                        }

                                        // Grouping for pti 12
                                        $grouped_data = [];
                                        if ($pti == 12) {
                                            foreach ($ALL_LIST as $row) {
                                                if (!isset($processTypes[$row['process_no']]) || $processTypes[$row['process_no']] != $pti) {
                                                    continue;
                                                }
                                                $machine_id_key = $row['machine_id'] ?: 'unassigned';
                                                if (!isset($grouped_data[$machine_id_key])) {
                                                    $grouped_data[$machine_id_key] = [
                                                        'name' => $row['machine'] ?: '未指派機台',
                                                        'items' => []
                                                    ];
                                                }
                                                $grouped_data[$machine_id_key]['items'][] = $row;
                                            }
                                        }
                                        ?>

                                        <?php if ($pti != 12): ?>
                                            <div class="row mb-3">
                                                <div class="col-md-2 pull-right">
                                                    <input type="text" id="global_search" class="form-control" placeholder="Search">
                                                </div>
                                            </div>
                                            <div class="table-responsive">
                                                <table id="datatable-buttons" class="table table-striped table-compact">
                                                    <thead>
                                                        <tr>
                                                            <th hidden="true">fid</th>
                                                            <th hidden="true">id</th>
                                                            <th>順序/更新</th>
                                                            <th>訂單交期</th>
                                                            <th>機台</th>
                                                            <th>客戶</th>
                                                            <th>BOM</th>
                                                            <th>料號</th>
                                                            <th>製程 / ERP備註 生管備註</th>
                                                            <th>數量</th>
                                                            <?php if ($pti == 1): ?><th>叫料/發圖</th><?php endif; ?>
                                                            <th>現場備註</th>
                                                            <th>發包日期</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        foreach ($ALL_LIST as $row) {
                                                            if (!isset($processTypes[$row['process_no']]) || $processTypes[$row['process_no']] != $pti) continue;
                                                            $is_null_or_empty = ($row['processing_sequence'] === null || trim($row['processing_sequence']) === '');
                                                            $sort_val = $is_null_or_empty ? '99999999' : str_pad($row['processing_sequence'], 8, '0', STR_PAD_LEFT);
                                                        ?>
                                                            <tr id="bom_ing_<?= $row['bom_ing_fid'] ?>" class="black-normal" data-row-id="<?= $row['bom_ing_fid'] ?>">
                                                                <td hidden="true"><?= $row['bom_ing_fid'] ?></td>
                                                                <td hidden="true" style="width:45px"><?= $row['bom_ing_id'] ?></td>
                                                                <td data-order="<?= htmlspecialchars($sort_val) ?>">
                                                                    <?= htmlspecialchars($row['processing_sequence']) ?>
                                                                    <form method="post" onsubmit="highlightRow(<?= $row['bom_ing_fid'] ?>)">
                                                                        <?php if ($user_pm==1): ?>
                                                                            <a href="../../views/pm/schedule_T5.php?pti=<?= $pti ?>&bom_ing_fid=<?= $row['bom_ing_fid'] ?>&id=<?= $_GET['id'] ?>&mi=<?= $row['machine_id'] ?>">
                                                                                <input type="button" class="btn btn-warning btn-xs update" value="更新" onclick="submitFormAndScrollToTop()">
                                                                            </a>
                                                                        <?php elseif ($user_ch==1): ?>
                                                                            <a href="../../views/pm/schedule_T5.php?pmi=1&pti=<?= $pti ?>&bom_ing_fid=<?= $row['bom_ing_fid'] ?>&id=<?= $_GET['id'] ?>&mi=<?= $row['machine_id'] ?>">
                                                                                <input type="button" class="btn btn-primary btn-xs update" value="增加備註" onclick="submitFormAndScrollToTop()">
                                                                            </a>
                                                                        <?php endif; ?>
                                                                    </form>
                                                                </td>
                                                                <td><?= $row['Delivery_date'] ?></td>
                                                                <td><?= strpos($row['machine'], '待排') === false ? $row['machine'] : '' ?></td>
                                                                <td><?= $row['Client_Name'] ?></td>
                                                                <td style="width:120px"><?= $row['bom'] ?></td>
                                                                <td style="width:200px">
                                                                    <button type='button' class='btn btn-xs btn-copy' title='複製料號' onclick='event.stopPropagation(); copyToClipboard("<?= htmlspecialchars($row['d_id'], ENT_QUOTES, 'UTF-8') ?>", this)'>
                                                                        <i class='fa fa-copy'></i>
                                                                    </button>
                                                                    <a href="/nas/<?= urlencode($row['bom']) ?>.jpg" target="_blank">
                                                                        <?= htmlspecialchars($row['d_id']) ?>
                                                                    </a>
                                                                </td>
                                                                <td><?= htmlspecialchars($row['ProcessName']) ?>
                                                                    <?php
                                                                    $remarks = [];
                                                                    if (!empty($row['ps'])) {
                                                                        $remarks[] = htmlspecialchars($row['ps']);
                                                                    }
                                                                    if (!empty($row['single_bet_ps'])) {
                                                                        $remarks[] = htmlspecialchars($row['single_bet_ps']);
                                                                    }
                                                                    if (!empty($remarks)) {
                                                                        echo ' / ' . implode(' ', $remarks);
                                                                    }
                                                                    ?></td>
                                                                <td style="width:150px"><?= $row['sqty'] ?></td>
                                                                <?php if ($pti == 1): ?><td><?= $row['pti01_ps'] ?></td><?php endif; ?>
                                                                <td style="width:150px"><?= $row['PS2'] ?></td>
                                                                <td style="width:150px"><?= $row['outsource_date'] ?></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($pti == 12): ?>
                                            <!-- Global Search -->
                                            <div class="row mb-3">
                                                <div class="col-md-2 pull-right">
                                                    <input type="text" id="global_search" class="form-control" placeholder="Search">
                                                </div>
                                            </div>
                                            <div class="x_title">
                                                <div class="clearfix"></div>
                                            </div>

                                            <!-- Per-machine panels -->
                                            <?php foreach ($grouped_data as $machine_id => $group): ?>
                                                <div class="row">
                                                    <div class="col-md-12">
                                                        <div class="x_panel">
                                                            <div class="x_title">
                                                                <h2>機台： <?= htmlspecialchars($group['name']) ?></h2>
                                                                <ul class="nav navbar-right panel_toolbox">
                                                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                                                </ul>
                                                                <div class="clearfix"></div>
                                                            </div>
                                                            <div class="x_content">
                                                                <div class="table-responsive">
                                                                    <table id="datatable-machine-<?= htmlspecialchars($machine_id) ?>" class="table table-striped table-compact datatable-machine" style="width:100%">
                                                                        <thead>
                                                                            <tr>
                                                                                <th>順序/更新</th>
                                                                                <th>訂單交期</th>
                                                                                <th>客戶</th>
                                                                                <th>BOM</th>
                                                                                <th>料號</th>
                                                                                <th>製程</th>
                                                                                <th>數量</th>
                                                                                <th>備註</th>
                                                                                <th>發包日期</th>
                                                                                <th>操作</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody>
                                                                            <?php usort($group['items'], function ($a, $b) {
                                                                                return (int)$a['processing_sequence'] <=> (int)$b['processing_sequence'];
                                                                            }); ?>
                                                                            <?php foreach ($group['items'] as $row): ?>
                                                                                <?php
                                                                                $is_null_or_empty = ($row['processing_sequence'] === null || trim($row['processing_sequence']) === '');
                                                                                $sort_val = $is_null_or_empty ? '99999999' : str_pad($row['processing_sequence'], 8, '0', STR_PAD_LEFT);
                                                                                ?>
                                                                                <tr>
                                                                                    <td data-order="<?= htmlspecialchars($sort_val) ?>">
                                                                                        <?= htmlspecialchars($row['processing_sequence']) ?>
                                                                                        <form method="post" onsubmit="highlightRow(<?= $row['bom_ing_fid'] ?>)">
                                                                                            <?php if ($user_pm==1): ?>
                                                                                                <a href="../../views/pm/schedule_T5.php?pti=<?= $pti ?>&bom_ing_fid=<?= $row['bom_ing_fid'] ?>&id=<?= $_GET['id'] ?>&mi=<?= $row['machine_id'] ?>">
                                                                                                    <input type="button" class="btn btn-warning btn-xs update" value="更新" onclick="submitFormAndScrollToTop()">
                                                                                                </a>
                                                                                            <?php elseif ($user_ch==1): ?>
                                                                                                <a href="../../views/pm/schedule_T5.php?pmi=1&pti=<?= $pti ?>&bom_ing_fid=<?= $row['bom_ing_fid'] ?>&id=<?= $_GET['id'] ?>&mi=<?= $row['machine_id'] ?>">
                                                                                                    <input type="button" class="btn btn-primary btn-xs update" value="增加備註" onclick="submitFormAndScrollToTop()">
                                                                                                </a>
                                                                                            <?php endif; ?>
                                                                                        </form>
                                                                                    </td>
                                                                                    
                                                                                    <td><?= htmlspecialchars($row['Delivery_date']) ?></td>
                                                                                    <td><?= htmlspecialchars($row['Client_Name']) ?></td>
                                                                                    <td><?= htmlspecialchars($row['bom']) ?></td>
                                                                                    <td>
                                                                                        <button type='button' class='btn btn-xs btn-copy' title='複製料號' onclick='event.stopPropagation(); copyToClipboard("<?= htmlspecialchars($row['d_id'], ENT_QUOTES, 'UTF-8') ?>", this)'>
                                                                                            <i class='fa fa-copy'></i>
                                                                                        </button>
                                                                                        <a href="/nas/<?= urlencode($row['bom']) ?>.jpg" target="_blank">
                                                                                            <?= htmlspecialchars($row['d_id']) ?>
                                                                                        </a>
                                                                                    </td>
                                                                                    <td><?= htmlspecialchars($row['ProcessName']) ?></td>
                                                                                    <td><?= htmlspecialchars($row['sqty']) ?></td>
                                                                                    <td><?= htmlspecialchars($row['PS2'] ?? '') ?></td>
                                                                                    <td><?= htmlspecialchars($row['outsource_date']) ?></td>
                                                                                    <td>
                                                                                        <!-- <?php if ($user['user_status'] == 3 || $user['user_status'] == 9): ?>
                                                                                            <?php if ($row['processing_sequence'] > 1): ?>
                                                                                                <a href="../../src/store/_schedule_T5_move_up_12.php?ps=<?= $row['processing_sequence'] ?>&mi=<?= $row['machine_id'] ?>" class="btn btn-warning btn-xs">上移</a>
                                                                                            <?php endif; ?>
                                                                                            <?php // 下移按鈕若非最大順序
                                                                                            ?>
                                                                                        <?php endif; ?> -->
                                                                                    </td>
                                                                                </tr>
                                                                            <?php endforeach; ?>
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                </div>
            </div>

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
    <!-- validator 按送出後的資料檢驗與重導網頁-->
    <!-- <script src="../../resource/js/validator.js"></script> -->
    <!-- Custom Theme Scripts -->
    <script src="../../resource/js/custom.min.js"></script>

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

    <script>
        $(document).ready(function() {
            // 從 PHP 獲取 pti 變數
            var pti = <?= json_encode($pti) ?>;
            var globalSearchInput = $('#global_search');

            // --- DataTables 初始化 ---
            if (pti == 12) {
                // PTI = 12: 初始化所有機台表格
                $('.datatable-machine').each(function() {
                    $(this).DataTable({
                        destroy: true, // 允許重新初始化
                        order: [ [0, 'asc'] ],
                        paging: false,
                        info: false,
                        searching: true, // API 搜尋需要此項為 true
                        dom: 'rt'        // 僅顯示表格，隱藏預設的搜尋框
                    });
                });
            } else {
                // PTI <> 12: 初始化單一表格
                $('#datatable-buttons').DataTable({
                    destroy: true, // 允許重新初始化
                    order: [ [2, "asc"] ],
                    paging: true,
                    info: true,
                    searching: true, // API 搜尋需要此項為 true
                    dom: 'lrtip'     // 隱藏預設的搜尋框 (f)
                });
            }

            // --- 全域搜尋事件處理 ---

            // 1. Keyup 進行搜尋
            globalSearchInput.on('keyup', function() {
                var term = $(this).val();
                if (pti == 12) {
                    $.fn.dataTable.tables({ api: true, selector: '.datatable-machine' }).search(term).draw();
                } else {
                    $('#datatable-buttons').DataTable().search(term).draw();
                }
            });

            // 2. Double-click 清除搜尋內容
            globalSearchInput.on('dblclick', function() {
                if ($(this).val() !== '') {
                    $(this).val(''); // 清除輸入框
                    // 觸發 keyup 事件來重新執行搜尋（此時搜尋詞為空），以清除篩選
                    globalSearchInput.trigger('keyup');
                }
            });
        });
    </script>

    <script>
        // --- Clipboard Copy Function ---
        // --- Fallback Function ---
        function fallbackCopyToClipboard(text, buttonElement) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed"; // Prevent scrolling issues
            textArea.style.top = "-9999px";
            textArea.style.left = "-9999px";
            textArea.setAttribute("readonly", ""); // Make it non-editable
            document.body.appendChild(textArea);
            textArea.focus(); // Focus on the textarea
            textArea.select(); // Select its content

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    // Visual feedback
                    const originalIcon = buttonElement.innerHTML;
                    buttonElement.innerHTML = '<i class="fa fa-check"></i>';
                    buttonElement.disabled = true;
                    setTimeout(() => {
                        buttonElement.innerHTML = originalIcon;
                        buttonElement.disabled = false;
                    }, 1000);
                }
            } catch (err) {
                alert('自動複製失敗，請手動複製。');
            } finally { // Ensure removal even if errors occur
                document.body.removeChild(textArea);
            }
        }

        function copyToClipboard(text, buttonElement) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    const originalIcon = buttonElement.innerHTML;
                    buttonElement.innerHTML = '<i class="fa fa-check"></i>';
                    buttonElement.disabled = true;
                    setTimeout(() => {
                        buttonElement.innerHTML = originalIcon;
                        buttonElement.disabled = false;
                    }, 1000);
                }).catch(function(err) {
                    fallbackCopyToClipboard(text, buttonElement);
                });
            } else {
                fallbackCopyToClipboard(text, buttonElement);
            }
        }
    </script>

</body>

</html>