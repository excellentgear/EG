<?php

session_start();

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$user_status   = $_SESSION['status'];

$msg=null;
@$id=$_GET['id'];
@$new=$_GET['new'];

//TG
$conn = new DBConnection();

IF(ISSET($_GET['pti'])){
    if($_GET['pti']==1){
        @$pti=1;
        @$pti_m=1;
        @$vw_pti='vw_pti'.'_'.$pti.'_list';
        @$maker_check_name='原一';
    }else{
        @$pti=$_GET['pti'];
        @$pti_m=$_GET['pti'];
        @$vw_pti='vw_pti'.'_'.$pti.'_list';
        @$maker_check_name='超正';
    };
}else{
    @$pti=12;
    @$pti_m=12;
    @$vw_pti='vw_pti'.'_'.$pti.'_list';
    @$maker_check_name='超正';
};


$tg_list = $conn->getAll("SELECT DISTINCT machine,`machine_id`
from $vw_pti
ORDER BY `machine_id`");

// 機台
@$machine_id_list = $conn->getAll("SELECT `machine_id`,`machine` FROM machine_list WHERE `machine_type_id`=$pti_m ORDER BY `machine_id`");


//準備更新TG
@$bom_ing_fid=null;
@$bom_ing_fid=$_GET['bom_ing_fid'];
@$mi=$_GET['mi'];

if($bom_ing_fid!=null){
$cmd = $db->prepare("SELECT * FROM $vw_pti WHERE bom_ing_fid=$bom_ing_fid");
$cmd->execute();
$row = $cmd->fetch();

@$specification      =$row['specification'];
@$machine_id         =$row['machine_id'];
@$machine            =$row['machine'];
@$processing_sequence=$row['processing_sequence'];
@$bom                =$row['bom'];
@$bom_ing_id         =$row['bom_ing_id'];
@$sqty               =$row['sqty'];
@$outsource_date     =$row['outsource_date'];
@$d_id               =$row['d_id'];
@$ps                 =$row['ps'];
};

@$pmi=$_GET['pmi'];


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

    .table {
        width: 100%;
        max-width: 100%;
    }

    .table th, .table td {
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
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        transition: all 0.3s;
        z-index: 1000;
    }

    .scroll-to-top:hover {
        background-color: rgba(255, 255, 255, 0.7);
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
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // 按下更新或排機台自動上移至頂端
    function submitFormAndScrollToTop() {
    document.querySelector('form').submit();
    window.scrollTo(0, 0);
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
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&new=1"><input type="button" class="btn btn-xs btn-success" value="新增"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=1"><input type="button" class="btn btn-xs btn-primary" value="車床"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=2"><input type="button" class="btn btn-xs btn-primary" value="銑床"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=4"><input type="button" class="btn btn-xs btn-primary" value="滾齒"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=33"><input type="button" class="btn btn-xs btn-primary" value="平研"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=11"><input type="button" class="btn btn-xs btn-primary" value="外研"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=12"><input type="button" class="btn btn-xs btn-primary" value="齒研"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=189"><input type="button" class="btn btn-xs btn-primary" value="其他製程"></a>
                            <a href="../../views/pages/schedule_TG.php?id=<?= $_GET['id']?>&pti=16"><input type="button" class="btn btn-xs btn-primary" value="雷刻與包裝"></a>
                        </div> 
                        <div class="title_left">
                        <h4>
                            <?php
                            if(!empty($_GET['message'])) {
                                if($_GET['message']=="success") {
                                    echo "<div id=\"message\" class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    新增成功
                                    </div>";
                                } else if($_GET['message']!="success"){
                                    $var=$_GET['message'];
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

                <?PHP if($bom_ing_fid!=null OR $new!=null){ ?>
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
                                        <?PHP if($new==1){ ?>
                                            <h2>新增待加工<SMALL>(同步新增至BOM)</SMALL></h2>
                                        <?php } else { ?>
                                            <h2>資料修改</h2>
                                        <?php } ?>
                                    </div>
                                </div>
                                <div class="x_content">

                                    <form method="POST" action="../../src/store/_updateTG.php?mi=<?=$mi?>&pmi=<?=$pmi?>&pti=<?= $pti?>&bom_ing_fid=<?= $bom_ing_fid ?>&id=<?= $id?>" class="form-horizontal form-label-left" novalidate>
                                    <span class="section"></span>
                                        <input type="hidden" id="bom_ing_fid" class="form-control col-md-3 col-xs-3" value="<?= $bom_ing_fid ?>" type="text">
                                        <input type="hidden" id="bom_ing_id" class="form-control col-md-3 col-xs-3" value="<?= $bom_ing_id ?>" type="text">
                                        
                                        <?PHP IF(ISSET($pmi)){ ?>
                                            <!-- <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="processing_sequence">順序 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-3">
                                                    <input id="processing_sequence" class="form-control col-md-7 col-xs-12" value="<?= $processing_sequence ?>" data-validate-length-range="6" data-validate-words="1" name="processing_sequence" placeholder="" required="required" type="text">
                                                </div>
                                            </div> -->

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="bom">BOM <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-4">
                                                    <?PHP if($new==1){ ?>
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
                                                    <?PHP if($new==1){ ?>
                                                        <input id="d_id" class="form-control col-md-7 col-xs-12" value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                    <?php } else { ?>
                                                        <input id="d_id" class="form-control col-md-7 col-xs-12" READONLY value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="machine_id"> 機台<?=$mi?> <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-3">
                                                    <select name="machine_id" id="machine_id">
                                                        <?PHP IF(ISSET($mi)){ 
                                                            $conn = new DBConnection();
                                                            $mi_one = $conn->getAll("SELECT `machine_id`,`machine` FROM machine_list 
                                                                                        WHERE `machine_id`=$mi");
                                                        foreach($mi_one as $mi_one){ ?>
                                                            <option name="machine_id" value="<?= $mi?>" selected><?= $mi_one['machine'] ?></option>
                                                        <?PHP } 
                                                            foreach($machine_id_list as $machine_id_list){ ?>
                                                            <option name="machine_id" value="<?= $machine_id_list['machine_id']?>"><?= $machine_id_list['machine'] ?></option>
                                                        <?php }} else { ?>
                                                            <option value="0">請選擇</option>
                                                        <?php foreach($machine_id_list as $machine_id_list){ ?>
                                                            <option name="machine_id" value="<?= $machine_id_list['machine_id']?>"><?= $machine_id_list['machine'] ?></option>
                                                        <?php }} ?>
                                                    </select>
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
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="machine_id"> 機台<?=$mi?> <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-3">
                                                    <select name="machine_id" id="machine_id">
                                                        <?PHP IF(ISSET($mi)){ 
                                                            $conn = new DBConnection();
                                                            $mi_one = $conn->getAll("SELECT `machine_id`,`machine` FROM machine_list 
                                                                                        WHERE `machine_id`=$mi");
                                                            foreach($mi_one as $mi_one){ ?>
                                                                <option name="machine_id" value="<?= $mi?>" selected><?= $mi_one['machine'] ?></option>
                                                        <?PHP } 
                                                            foreach($machine_id_list as $machine_id_list){ ?>
                                                            <option name="machine_id" value="<?= $machine_id_list['machine_id']?>"><?= $machine_id_list['machine'] ?></option>
                                                        <?php }}?>
                                                            
                                                    </select>
                                                </div>
                                            </div>

                                            <?PHP if($new==1){ ?>
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name">客戶 <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <input id="Client_Name" class="form-control col-md-7 col-xs-12" value="<?= $Client_Name ?>" data-validate-length-range="2" data-validate-words="1" name="Client_Name" required="required" type="text">
                                                    </div>
                                                </div>
                                            <?php } else { ?>
                                            <?php } ?>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="bom">BOM <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-4">
                                                    <?PHP if($new==1){ ?>
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
                                                    <?PHP if($new==1){ ?>
                                                        <input id="d_id" class="form-control col-md-7 col-xs-12" value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                    <?php } else { ?>
                                                        <input id="d_id" class="form-control col-md-7 col-xs-12" READONLY value="<?= $d_id ?>" data-validate-length-range="6" data-validate-words="1" name="d_id" required="required" type="text">
                                                    <?php } ?>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="specification">規格<span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-3">
                                                    <?PHP if($new==1){ ?>
                                                        <input id="specification" class="form-control col-md-7 col-xs-12" value="<?= $specification ?>" data-validate-length-range="6" data-validate-words="1" name="specification" required="required" type="text">
                                                    <?php } else { ?>
                                                        <input id="specification" class="form-control col-md-7 col-xs-12" READONLY value="<?= $specification ?>" data-validate-length-range="6" data-validate-words="1" name="specification" required="required" type="text">
                                                    <?php } ?>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="sqty">數量 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-3">
                                                    <input id="sqty" class="form-control col-md-7 col-xs-12" value="<?= $sqty ?>" data-validate-length-range="6" data-validate-words="1" name="sqty" required="required" type="text">
                                                </div>
                                            </div>

                                            <?PHP if($new==1){ ?>
                                            <?php } else { ?>
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="outsource_date">發包日期 <span class="required"></span>
                                                    </label>
                                                    <div class="col-md-2 col-sm-2 col-xs-3">
                                                        <input id="outsource_date" class="form-control col-md-7 col-xs-12" READONLY value="<?= $outsource_date ?>" data-validate-length-range="2" data-validate-words="1" name="outsource_date" required="required" type="text">
                                                    </div>
                                                </div>
                                            <?php } ?>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ps">備註 <span class="required"></span>
                                                </label>
                                                <div class="col-md-2 col-sm-2 col-xs-3">
                                                    <input id="ps" class="form-control col-md-7 col-xs-12" value="<?= $ps ?>" data-validate-length-range="6" data-validate-words="1" name="ps" required="required" type="text">
                                                </div>
                                            </div>
                                        <?php } ?>

                                        <div class="ln_solid"></div>
                                        <div class="form-group">
                                            <div class="col-md-6 col-md-offset-3">
                                                <?PHP if($new==1){ ?>
                                                    <a href="../../views/pages/schedule_TG.php?pti=<?= $pti?>&id=<?= $_GET['id']?>"><input type="button" class="btn btn-primary" value="取消 / 清除"></a>
                                                    <button onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>) id="send" name="newTG" type="submit" class="btn btn-success">送出</button>
                                                <?php } else { ?>
                                                    <a href="../../views/pages/schedule_TG.php?pti=<?= $pti?>&id=<?= $_GET['id']?>"><input type="button" class="btn btn-primary" value="取消 / 清除"></a>
                                                    <button onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>) id="send" name="updataTG" type="submit" class="btn btn-success">送出</button>
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
                    <!-- TG -->
                    

                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <?php if(isset($pti)) { 
                                            $conn = new DBConnection();
                                            $pti_one = $conn->getAll("SELECT `process_type_id`,`process_type` FROM process_type 
                                                                        WHERE `process_type_id`=$pti");
                                            foreach($pti_one as $pti_one) { ?>
                                                <h2><?= $pti_one['process_type'] ?> 排程</h2>
                                        <?php }} ?>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                        </ul>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">
                                        <p class="text-muted font-13 m-b-30"></p>
                                            
                                        <?php foreach ($tg_list as $tg_list) { ?>
                                        <div class="table-responsive">
                                            <table id="datatable-buttons" class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th colspan="8">機台： <?= $tg_list['machine']; $machine_id = $tg_list['machine_id']; ?></th>
                                                    </tr>
                                                    <tr>
                                                        <th>順序</th>
                                                        <th>BOM</th>
                                                        <th>料號</th>
                                                        <th>規格</th>
                                                        <th>數量</th>
                                                        <th>備註</th>
                                                        <th>發包日期</th>
                                                        <th>操作</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php 
                                                    $conn = new DBConnection();
                                                    // 取得所有 process_no 的 process_type_id
                                                    $processTypeQuery = "SELECT ProcessNo, process_type_id FROM process_no";
                                                    $processStmt = $db->prepare($processTypeQuery);
                                                    $processStmt->execute();
                                                    $processTypes = $processStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                                        
                                                    // 查詢資料中最大的順序號碼（使用 process_type_id = $pti）
                                                    $maxOrderQuery = "SELECT MAX(processing_sequence) AS max_order
                                                                      FROM $vw_pti
                                                                      WHERE process_type_id = $pti AND machine_id = :machine_id AND maker_id LIKE '%$maker_check_name%'";
                                                    $stmt = $db->prepare($maxOrderQuery);
                                                    $stmt->execute(['machine_id' => $machine_id]);
                                                    $maxOrderResult = $stmt->fetch();
                                                    $maxOrder = $maxOrderResult['max_order'];
                                        
                                                    $ALL_LIST = $conn->getAll("SELECT d_id,`bom_ing_fid`,`bom_ing_id`,`bom`,`machine_id`,`process_no`,
                                                                                `maker_id`,`sqty`,`processing_sequence`,ProcessName,`processing_state`,`ps`,
                                                                                `outsource_date`,`return_date`,`Created_By`,`return_date`
                                                                                FROM $vw_pti
                                                                                WHERE machine_id = '$machine_id'
                                                                                ORDER BY `processing_sequence` ASC");

                                                    foreach ($ALL_LIST as $ALL_LIST) {
                                                        if (isset($processTypes[$ALL_LIST['process_no']]) && $processTypes[$ALL_LIST['process_no']] == $pti) {
                                                ?>
                                                <tr id="bom_ing_<?php echo $bom_ing_fid; ?>" class="black-normal" data-row-id="<?= $ALL_LIST['bom_ing_fid'] ?>">
                                                    <td hidden="true"><?= $ALL_LIST['bom_ing_fid'] ?></td>
                                                    <td hidden="true" style="width: 45px"><?= $ALL_LIST['bom_ing_id'] ?></td>
                                                    <td>
                                                        <form method="post" onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>)">
                                                            <?php if($user['user_status']==9 or $user['user_status']==3){ ?>
                                                                <a href="../../views/pages/schedule_TG.php?pti=<?= $pti ?>&bom_ing_fid=<?= $ALL_LIST['bom_ing_fid'] ?>&id=<?= $_GET['id'] ?>&mi=<?= $ALL_LIST['machine_id'] ?>"><input type="button" name="updateTG" class="btn btn-warning btn-xs update" value="更新" onclick="submitFormAndScrollToTop()"></a>
                                                            <?php }elseif($user['user_status']==2){ ?>
                                                                <a href="../../views/pages/schedule_TG.php?pmi=1&pti=<?= $pti ?>&bom_ing_fid=<?= $ALL_LIST['bom_ing_fid'] ?>&id=<?= $_GET['id'] ?>&mi=<?= $ALL_LIST['machine_id'] ?>"><input type="button" name="updateTG" class="btn btn-primary btn-xs update" value="排機台" onclick="submitFormAndScrollToTop()"></a>
                                                                <!-- <?= $ALL_LIST['processing_sequence'] ?>/<?= $maxOrder ?> 排序/總數 -->
                                                            <?php }; ?>
                                                        </form>    
                                                    </td>
                                                    <td style="width: 120px"><?= $ALL_LIST['bom'] ?></td>
                                                    <td style="width: 200px"><?= $ALL_LIST['d_id'] ?></td>
                                                    <td><?= $ALL_LIST['ProcessName'] ?><?= $ALL_LIST['bomspecification_ing_fid'] ?></td>
                                                    <td style="width: 150px"><?= $ALL_LIST['sqty'] ?></td>
                                                    <td style="width: 150px"><?= $ALL_LIST['ps'] ?></td>
                                                    <td style="width: 150px"><?= $ALL_LIST['outsource_date'] ?></td>
                                                    <td class="btn-container">
                                                    <?php if ($ALL_LIST['processing_sequence'] == 1) { ?>
                                                        <form action="../../src/store/_schedule_TG_move_down.php?ps=<?= $ALL_LIST['processing_sequence'] ?>&pti=<?= $pti ?>&id=<?= $_GET['id'] ?>&mi=<?= $ALL_LIST['machine_id'] ?>" method="post" onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>)">
                                                            <input type="hidden" name="id" value="<?= $ALL_LIST['bom_ing_fid'] ?>">
                                                            <input type="submit" value="下移" class="btn btn-warning btn-xs update">
                                                        </form>
                                                    <?php } elseif ($ALL_LIST['processing_sequence'] < $maxOrder) { ?>
                                                        <form action="../../src/store/_schedule_TG_move_up.php?ps=<?= $ALL_LIST['processing_sequence'] ?>&pti=<?= $pti ?>&id=<?= $_GET['id'] ?>&mi=<?= $ALL_LIST['machine_id'] ?>" method="post" onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>)">
                                                            <input type="hidden" name="id" value="<?= $ALL_LIST['bom_ing_fid'] ?>">
                                                            <input type="submit" value="上移" class="btn btn-warning btn-xs update">
                                                        </form>
                                                        <form action="../../src/store/_schedule_TG_move_down.php?ps=<?= $ALL_LIST['processing_sequence'] ?>&pti=<?= $pti ?>&id=<?= $_GET['id'] ?>&mi=<?= $ALL_LIST['machine_id'] ?>" method="post" onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>)">
                                                            <input type="hidden" name="id" value="<?= $ALL_LIST['bom_ing_fid'] ?>">
                                                            <input type="submit" value="下移" class="btn btn-warning btn-xs update">
                                                        </form>
                                                    <?php } else { ?>
                                                        <form action="../../src/store/_schedule_TG_move_up.php?ps=<?= $ALL_LIST['processing_sequence'] ?>&pti=<?= $pti ?>&id=<?= $_GET['id'] ?>&mi=<?= $ALL_LIST['machine_id'] ?>" method="post" onsubmit="highlightRow(<?= $ALL_LIST['bom_ing_fid'] ?>)">
                                                            <input type="hidden" name="id" value="<?= $ALL_LIST['bom_ing_fid'] ?>">
                                                            <input type="submit" value="上移" class="btn btn-warning btn-xs update">
                                                        </form>
                                                    <?php } ?>
                                                    </td>
                                                </tr>
                                                <?php }} ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <?php } ?>
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

</body>

</html>