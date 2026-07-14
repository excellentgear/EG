<?php
session_start();
//2024.03.22 ok
include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

//2024.03.26 尚未完成

//料號
@$conn = new DBConnection();
//上方左欄使用
// @$ALL = $conn->getAll("SELECT * FROM `QC` ORDER BY QC_Id");

@$ALL_Sce = $conn->getAll("SELECT bom_ing.bom,bom_ing.sqty,d_setting.d_id,d_setting.D_Setting_Id, bom.Client_Name,
qc.ProcessNo FROM bom left join d_setting on bom.d_id=d_setting.d_id LEFT join bom_ing on bom_ing.bom=bom.bom left join qc on qc.d_id=d_setting.d_id
ORDER BY bom.bom");

//FROM _UPDATEIPQC.PHP
@$d_id          = $_SESSION['d_id'];
@$D_Setting_Id  = $_SESSION['D_Setting_Id'];
@$Drawing_No    = $_SESSION['Drawing_No']; //料號
@$Spec_No       = $_SESSION['Spec_No']; //規格
@$Client_Name   = $_SESSION['Client_Name']; //客戶名稱
@$sqty          = $_SESSION['sqty'];
@$ProcessNo     = $_SESSION['ProcessNo'];
@$bom           = $_SESSION['bom'];

// @$List = $conn->getAll("SELECT * FROM `QC`");
// @$qc_tool_list = $conn->getAll("SELECT * FROM `qc_tool_list`");

// if($QC_Id



// @$QC_TOTAL_Del=$_SESSION['QC_TOTAL_Del'];

if($D_Setting_Id!=""){
@$ProcessNo_list = $conn->getAll("SELECT ProcessNo FROM qc WHERE d_id=$d_id AND NOT(d_id IS NULL) ORDER BY ProcessNo");
};

// @$opne_qc_setting_right=$_SESSION['opne_qc_setting_right'];

// //2024.03.28 開始
// @$IPQC_LIST=$conn->getall("SELECT bom.bom,bom_ing.sqty,
// d_setting.d_id,d_setting.D_Setting_Id,d_setting.Drawing_No,d_setting.Spec_No,
// qc.QC_Id,qc.QC_TOTAL,qc.ProcessNo,qc.QC_Type,
// qc_date.QC_List_No,qc_date.QC_MV,qc_date.QC_Date_Result,
// qc_tool.Tool_No,qc_tool_list.QC_Tool,
// qc_result.Maker_Id,
// QC_Scope.QC_Scope,QC_Scope.QC_S_Scope,QC_Scope.Upper_Limit,QC_Scope.Lower_Limit
// FROM bom 
// LEFT join bom_ing on bom_ing.bom=bom.bom
// LEFT join d_setting on d_setting.d_id=bom.d_id
// LEFT join qc ON qc.d_id=d_setting.d_id
// LEFT join qc_date ON qc_date.QC_Id=qc.QC_Id
// LEFT join qc_result ON qc_result.QC_Id=qc.QC_Id
// LEFT join qc_scope ON qc_scope.QC_Id=qc.QC_Id
// LEFT join qc_tool ON qc_tool.Tool_id=qc_scope.QC_Tool
// LEFT join qc_tool_list ON qc_tool_list.QC_Tool_List_id=qc_tool.Tool_id
// WHERE bom.bom=".$bom." AND qc.ProcessNo=".$ProcessNo);

//2024.04.02 

if($bom!="" && $ProcessNo!=""){
@$IPQC_LEFT_LIST=$conn->getall("SELECT bom.bom,qc.ProcessNo,qc.QC_Id,QC_Scope.QC_List_No,
QC_Scope.QC_Scope,QC_Scope.QC_S_Scope,QC_Scope.Upper_Limit,QC_Scope.Lower_Limit
FROM bom 
LEFT join bom_ing on bom_ing.bom=bom.bom
LEFT join qc ON qc.d_id=bom.d_id
LEFT join qc_scope ON qc_scope.QC_Id=qc.QC_Id
WHERE bom.bom='.$bom.' AND qc.ProcessNo=$ProcessNo");

@$IPQC_COUNT=$conn->getall("SELECT max(qc_date.QC_List_No)
FROM qc_date
LEFT JOIN qc_result on qc_result.QC_Result_Id=qc_date.QC_Result_Id
LEFT JOIN qc on qc.QC_Id=qc_date.QC_Id
LEFT JOIN bom on bom.d_id=qc.d_id
LEFT JOIN d_setting on d_setting.d_id=qc.d_id
LEFT JOIN qc_scope on qc_scope.QC_Scope_id=qc_date.QC_Scope_id
WHERE bom.bom='.$bom.' and qc.ProcessNo=$ProcessNo LIMIT 1");

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

    <title>QC待驗清單</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- iCheck -->
    <link href="../../resource/css/green.css" rel="stylesheet">
    <!-- Datatables -->
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/responsive.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
</head>
<script language="javascript" type="text/javascript">
    window.onload = function () {
        var tableLine = document.getElementById("number");
        for (var i = 0; i < tableLine.rows.length; i++) {
            tableLine.rows[i].cells[0].innerHTML = (i + 1);
        }
    }
</script>
<body class="nav-md">
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
                                if(!empty($_GET['message'])) {
                                    if($_GET['message']=="success") {
                                        echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                        <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                        成功
                                        </div>";
                                    } else {
                                        $var=$_GET['message'];
                                        echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                        <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                        $var
                                        </div>";
                                    }
                                }
                                ?>
                            </h4>
                        </div>
                    </div>

                    
                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <?php if ($d_id ==""){ ?>
                                            <h2>請先選擇要設定的料號</h2>
                                        <?php } else { ?>
                                            <input type="hidden" id="d_id" name="d_id" value="<?= $d_id ?>"><!--id!-->
                                            <h2 id="D_Setting_Id"><?= $D_Setting_Id ?> <small><?= $Drawing_No ?></small></h2>
                                        <?php } ?>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                            </li>
                                        </ul>
                                        <div class="clearfix"></div>
                                    </div>
                                        <div class="x_content">
                                        <!-- <div class="item form-group"> -->
                                            <!-- 上方欄 start -->
                                            <p class="text-muted font-13 m-b-30">
                                            </p>
                                            <div class="row">
                                                <div class="col-md-8 col-sm-8 col-xs-8">
                                            <?php if ($d_id ==""){ 
                                                } else { ?>
                                            
                                                    <div class="x_panel">
                                                        <h2>進料檢 <?= $ProcessNo ?></h2>
                                                        <?php
                                                            if($ProcessNo!=""){ ?>
                                                                <h2>
                                                                <h2>製令：<?= $bom ?></h2>
                                                                <?php switch($QC_Type){
                                                                        case "in":
                                                                            echo '<small>進料檢 - '.$ProcessNo.'</small>';
                                                                        break;
                                                                        case "out":
                                                                            echo '<small>出貨檢 - '.$ProcessNo.'</small>';
                                                                        break;
                                                                        case "all":
                                                                            echo '<small>進 / 出貨檢 - '.$ProcessNo.'</small>';
                                                                        break; } ?>
                                                            <?php } ?>
                                                                </h2>
                                                            <!-- <ul class="nav navbar-right panel_toolbox">
                                                                <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                                                </li>
                                                            </ul> -->
                                                            <div class="clearfix"></div>
                                                        <div class="x_content">
                                                            <p class="text-muted font-13 m-b-30">
                                                            </p>
                                                            <form action="../../src/store/_updateQC_id.php" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                                                <input type="hidden" id="QC_Id" name="QC_Id" value="<?= $QC_Id ?>"><!--KEY值!-->
                                                                    <?php if($QC_TOTAL==""){ ?>
                                                                        <table class="table table-bordered">
                                                                            <tbody>
                                                                                <tr>
                                                                                    <td width ="50">
                                                                                        <label class="control-label col-xs-12" for="ProcessNo">廠商 
                                                                                        </label>
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                        <input id="ProcessNo" class="" name="ProcessNo" data-validate-length-range="2"
                                                                                            required minlength="0" maxlength="10" size="6" style="font-size:15px"
                                                                                            data-validate-words="1" required="required" type="text"
                                                                                            class="form-control col-md-7 col-xs-12">
                                                                                    </td>
                                                                                    <td width ="50">
                                                                                        <label class="control-label col-xs-12" for="sqty">數量 
                                                                                        </label>
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                        <input id="sqty" class="" name="sqty" data-validate-length-range="2"
                                                                                            required minlength="0" maxlength="10" size="6" style="font-size:15px"
                                                                                            data-validate-words="1" required="required" type="text"
                                                                                            class="form-control col-md-7 col-xs-12" value="<?=$sqty ?>">
                                                                                    </td>
                                                                                    <td width ="100" rowspan="5">
                                                                                        <label for="floatingTextarea2">特採原因 / 備註</label>
                                                                                        </label> 
                                                                                        <div class="form-floating">
                                                                                            <textarea class="form-control" placeholder="Leave a comment here" id="floatingTextarea2" style="height: 100px"></textarea>
                                                                                            <label for="floatingTextarea2">特採需備註放行人員</label>
                                                                                        </div>
                                                                                    </td>
                                                                                </tr>
                                                                                <tr>
                                                                                    <td width ="50">
                                                                                            <label class="control-label col-xs-12" for="ProcessNo">抽樣數 
                                                                                            </label>
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                        <input id="ProcessNo" class="" name="ProcessNo" data-validate-length-range="2"
                                                                                            required minlength="0" maxlength="10" size="6" style="font-size:15px"
                                                                                            data-validate-words="1" required="required" type="text"
                                                                                            class="form-control col-md-7 col-xs-12">
                                                                                    </td>
                                                                                    <td width ="50">
                                                                                        <label class="control-label col-xs-12" for="ProcessNo">判定 
                                                                                        </label>
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                        <input id="ProcessNo" class="" name="ProcessNo" data-validate-length-range="2"
                                                                                            required minlength="0" maxlength="10" size="6" style="font-size:15px"
                                                                                            data-validate-words="1" required="required" type="text"
                                                                                            class="form-control col-md-7 col-xs-12">
                                                                                    </td>
                                                                                </tr>
                                                                                <tr>
                                                                                    <td width ="50">
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                    </td>
                                                                                    <td width ="50">
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                    </td>
                                                                                </tr>
                                                                                <tr>
                                                                                    <td width ="50">
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                    </td>
                                                                                    <td width ="50">
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                    </td>
                                                                                </tr>
                                                                                <tr>
                                                                                    <td width ="50">
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                    </td>
                                                                                    <td width ="50">
                                                                                    </td>
                                                                                    <td width ="100">
                                                                                    </td>
                                                                                </tr>
                                                                            </table>
                                                                                        <div class="item form-group">
                                                                                            <label class="control-label col-xs-12">
                                                                                            </label>
                                                                                            <button name="QC_TOTAL_Setting" type="submit" class="btn btn-primary">更新</button>
                                                                                        </div>
                                                                    <?php
                                                                    }else if($QC_TOTAL_Del==9){ ?>
                                                                        <div class="item form-group">
                                                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                                            </label>
                                                                            <button name="QC_TOTAL_Del_dbCHECK" type="submit" class="btn btn-primary btn-danger">確認刪除</button>
                                                                            &emsp;&emsp;
                                                                            <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                                        </div>
                                                                    <?php
                                                                    }else{ ?>
                                                                        <div class="item form-group">
                                                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                                            </label>
                                                                            <button name="QC_TOTAL_Del" type="submit" class="btn btn-primary btn-danger">刪除</button>
                                                                            &emsp;&emsp;
                                                                            <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                                        </div>
                                                                    <?php
                                                                    }
                                                                    ?>
                                                            </form>
                                                        </div>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                            <!-- 上方欄 end -->
                                            
                                            <!-- 中欄 start 2024.04.02 本 TABLE tbody 測試ok--> 
                                                <table class="table table-striped table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>尺寸 / 編號</th>
                                                        <?php //2024.04.02 II 要加入判斷是否已有資料，再決定顯示筆數
                                                        //count($IPQC_COUNT['QC_Scope_id'])
                                                        // foreach ($IPQC_COUNT as $IPQC_COUNT) {
                                                            if($bom!="" && $ProcessNo!=""){
                                                                if($IPQC_COUNT!=""){
                                                                    $ii=$IPQC_COUNT; //2024.04.08 輸出變成 Array
                                                                } else {
                                                                    $ii=10;
                                                                }
                                                            

                                                            for ($i = 1; $i <= $ii; $i++) {
                                                            ?>
                                                                <th><?= $ii ?></th>
                                                            <?php
                                                            }
                                                            ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody> 
                                                    <?php
                                                                foreach ($IPQC_LEFT_LIST as $IPQC_LEFT_LIST) { //60、27 ?> 
                                                                <tr>
                                                                    <?php
                                                                        $IPQC_LIST_for_left=$conn->getall("SELECT qc_scope.QC_List_No,qc_scope.QC_Scope,qc_date.QC_MV,qc_date.QC_Date_Result,
                                                                        qc_date.QC_Scope_id,qc_scope.QC_Id
                                                                        FROM qc_date
                                                                        LEFT JOIN qc_scope ON qc_date.QC_Id=qc_scope.QC_Id and qc_scope.QC_List_No=qc_date.QC_Scope_id
                                                                        WHERE qc_scope.QC_Id=".$IPQC_LEFT_LIST['QC_Id']." AND qc_date.QC_Scope_id=".$IPQC_LEFT_LIST['QC_List_No']); //抓QC_Scope第一筆待驗
                                                                     ?>
                                                                    <td><?= $IPQC_LEFT_LIST['QC_Scope'] ?></td>
                                                                    <?php
                                                                        foreach ($IPQC_LIST_for_left as $IPQC_LIST_for_left) { ?>
                                                                            <td><?= $IPQC_LIST_for_left['QC_MV'] ?></td>
                                                                        <?php } ?>
                                                                </tr>
                                                        <?php }}
                                                    // }
                                                     ?>
                                                    </tbody>
                                                </table>
                                            <!-- 中欄 end -->


                                                <?php
                                                if($QC_Skip==1 or $QC_Id ==""){
                                                } else{
                                                    ?>
                                            <div class="row">
                                                <div class="col-md-12 col-sm-12 col-xs-12">
                                                    <?php if ($d_id ==""){ } else { ?>
                                                    <div class="x_panel">
                                                        <div class="x_title">
                                                            <!-- <h2></h2> -->
                                                            <ul class="nav navbar-right panel_toolbox">
                                                                <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                                                </li>
                                                            </ul>
                                                            <div class="clearfix"></div>
                                                        </div>
                                                        <div class="x_content">
                                                            <p class="text-muted font-13 m-b-30">
                                                            </p>
                                                            <table class="table table-striped table-bordered">
                                                                <thead>
                                                                    <tr>
                                                                        <!-- <th width ="10">QC_Scope_id</th>
                                                                        <th width ="10">QC_id</th> -->
                                                                        <th width ="10">部位編號</th>
                                                                        <th width ="80">量測工具</th>
                                                                        <th width ="30">圖面尺寸</th>
                                                                        <th width ="200">特殊尺寸</th>
                                                                        <th width ="30">上限</th>
                                                                        <th width ="30">下限</th>
                                                                        <th width="30">
                                                                            <a href="../../src/store/_NewQC.php?QC_Scope_id=<?=$QC_Scope_id ?>&QC_Id=<?=$QC_Id ?>&QC_TOTAL=<?=$QC_TOTAL ?>"><input type="button" name="NewQC_scope" class="btn btn-danger btn-xs delete" value="新增一格"></a>
                                                                        </th>
                                                                    </tr>
                                                                </thead>
                                                                <!-- id="number" -->
                                                                <tbody>
                                                                    <?php
                                                                        // $ii=$qc_scope_list['QC_List_No'];
                                                                        foreach ($qc_scope_list as $qc_scope_list) {
                                                                    ?>
                                                                        <tr>
                                                                        
                                                                            <!-- <td><?= $qc_scope_list['QC_Scope_id'] ?></td>
                                                                            <td><?= $qc_scope_list['QC_Id'] ?></td> -->
                                                                            <td><?= $qc_scope_list['QC_List_No'] ?></td>
                                                                            <td><?= $qc_scope_list['QC_Tool'] ?></td>
                                                                            <td><?= $qc_scope_list['QC_Scope'] ?></td>
                                                                            <td>
                                                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                                                <?php
                                                                                    if($qc_scope_list['QC_S_Scope']==1){ ?>
                                                                                        <input id="QC_S_Scop" checked class="QC_S_Scope" 
                                                                                        data-validate-length-range="2" data-validate-words="1" 
                                                                                        name="QC_S_Scope" type="checkbox"
                                                                                        value="1">
                                                                                <?php } else{ ?>
                                                                                        <input id="QC_S_Scope" class="QC_S_Scope" 
                                                                                        data-validate-length-range="2" data-validate-words="1" 
                                                                                        name="QC_S_Scope" type="checkbox"
                                                                                        value="ok">
                                                                                
                                                                                <?php } ?>
                                                                                </div>
                                                                            </td>
                                                                                <td><?= $qc_scope_list['Upper_Limit'] ?></td>
                                                                                <td><?= $qc_scope_list['Lower_Limit'] ?></td>
                                                                            <td>
                                                                                <a href="../../src/store/_update_qc_scope.php?QC_Scope_id=<?= $qc_scope_list['QC_Scope_id'] ?>&QC_Id=<?=$qc_scope_list['QC_Id'] ?>&QC_TOTAL=<?=$QC_TOTAL ?>">
                                                                                    <input type="button" name="update_qc_scope" class="btn btn-warning btn-xs update" value="更新"></a>
                                                                                <a href="../../src/store/_delete_qc_scope.php?QC_Scope_id=<?= $qc_scope_list['QC_Scope_id'] ?>&QC_Id=<?=$qc_scope_list['QC_Id'] ?>&QC_TOTAL=<?=$QC_TOTAL ?>">
                                                                                    <input type="button" name="delete_qc_scope" class="btn btn-danger btn-xs delete" value="刪除"></a>
                                                                            </td>
                                                                        </tr>
                                                                    <?php
                                                                    }
                                                                    ?>
                                                                </tbody>
                                                            </table>
                                                                
                                                                <!-- <div class="ln_solid">                                                
                                                                    <div class="form-group">
                                                                        <div class="col-md-6 col-md-offset-3">
                                                                        <form action="../../src/store/_QCSetting_UpDate.php" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                                                            <button name="resetpSetting" type="submit"
                                                                                class="btn btn-primary">取消</button>
                                                                        </from>
                                                                        </div>
                                                                    </div>
                                                                </div> -->
                                                                <?php } ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                

                 <!-- 料號總覽 -->
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-12 col-sm-12 col-xs-12">
                                    <div class="x_panel">
                                        <div class="x_title">
                                            <h2>料號總覽</h2>
                                            <ul class="nav navbar-right panel_toolbox">
                                                <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                                </li>
                                                <li><a class="close-link"><i class="fa fa-close"></i></a>
                                                </li>
                                            </ul>
                                        <div class="clearfix"></div>
                                        </div>

                        <!-- 呈現料號資料   -->
                                        <table id="datatable-buttons" class="table table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>id</th>
                                                    <th>BOM</th>
                                                    <th>料號</th>
                                                    <th>[製程] 總數</th>
                                                    <th></th>

                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                foreach ($ALL_Sce as $ALL_Sce) {
                                                    ?>
                                                    <tr>
                                                        <!-- type=hidden -->
                                                        <td name="d_id">
                                                            <?= $ALL_Sce['d_id'] ?>
                                                        </td>
                                                        <td name="BOM">
                                                            <a href="../pm/bom/<?= $ALL_Sce['bom'] ?>.xlsm"><?= $ALL_Sce['bom'] ?>
                                                        </td>
                                                        <td name="D_Setting_Id">
                                                        <a href="../pm/bom/<?= $ALL_Sce['bom'] ?>.jpg"><?= $ALL_Sce['D_Setting_Id'] ?>
                                                        </td>
                                                        <td name="ProcessNo">
                                                        <?php if($ALL_Sce['ProcessNo']==""){ ?>
                                                            [未設定 BOM] <?= $ALL_Sce['sqty'] ?>
                                                        <?php } else { ?>
                                                            [<?= $ALL_Sce['ProcessNo'] ?>] <?= $ALL_Sce['sqty'] ?>
                                                        <?php } ?>
                                                        </td>
                                                        <td>
                                                            <a
                                                                href="../../src/store/_updateIPQC.php?d_id=<?= $ALL_Sce['d_id'] ?>&bom=<?= $ALL_Sce['bom'] ?>&ProcessNo=<?= $ALL_Sce['ProcessNo'] ?>">
                                                                <input type="button" name="updateIPQC" class="btn btn-warning btn-xs update"
                                                                value="設定">
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </form>
                                            
                    <!-- 線圖 -->

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
</body>
</html>
