<?php
session_start();
//2024.03.22 ok
include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

//料號
@$conn = new DBConnection();
//上方左欄使用
@$ALL = $conn->getAll("SELECT * FROM `QC` ORDER BY QC_Id");
@$QC_Id = $_SESSION['QC_Id'];
@$QC_V = $_SESSION['QC_V'];
@$Spec_No = $_SESSION['Spec_No'];
@$ProcessNo = $_SESSION['ProcessNo'];
//上方右欄使用
@$QC_List_No = $_SESSION['QC_List_No'];
@$QC_TOTAL=$_SESSION['QC_TOTAL'];
@$QC_Type = $_SESSION['QC_Type'];
@$QC_Tool = $_SESSION['QC_Tool'];
@$QC_Skip = $_SESSION['QC_Skip'];
@$QC_Scope = $_SESSION['QC_Scope'];
@$QC_S_Scope = $_SESSION['QC_S_Scope'];
@$Upper_Limit = $_SESSION['Upper_Limit'];
@$Lower_Limit = $_SESSION['Lower_Limit'];
@$QC_Scope_id=$_SESSION['QC_Scope_id'];

@$ALL_Sce = $conn->getAll("SELECT d_setting.d_id,d_setting.D_Setting_Id, d_setting.Drawing_No, d_setting.Spec_No, d_setting.Client_Name,
qc.ProcessNo FROM `d_setting` LEFT join qc on d_setting.d_id=qc.d_id ORDER BY d_setting.Drawing_No");

@$d_id = $_SESSION['d_id'];
@$D_Setting_Id = $_SESSION['D_Setting_Id'];
@$Drawing_No = $_SESSION['Drawing_No']; //料號
@$Spec_No = $_SESSION['Spec_No']; //規格
@$Client_Name = $_SESSION['Client_Name']; //客戶名稱

@$List = $conn->getAll("SELECT * FROM `QC`");
@$qc_tool_list = $conn->getAll("SELECT * FROM `qc_tool_list`");

if($QC_Id!=""){
@$qc_scope_list = $conn->getAll("SELECT qc_scope.QC_Id,qc_tool_list.QC_Tool,qc_scope.QC_List_No,qc_scope.QC_Scope_id,qc_scope.QC_Scope,qc_scope.QC_S_Scope,qc_scope.Upper_Limit,qc_scope.Lower_Limit FROM `qc_scope` LEFT JOIN `qc_tool_list` ON qc_scope.QC_Tool=qc_tool_list.QC_Tool_List_id WHERE qc_scope.QC_Id=$QC_Id order by QC_List_No");
};


@$QC_TOTAL_Del=$_SESSION['QC_TOTAL_Del'];

if($D_Setting_Id!=""){
@$ProcessNo_list = $conn->getAll("SELECT ProcessNo FROM qc WHERE d_id=$d_id AND NOT(d_id IS NULL) ORDER BY ProcessNo");
};

@$opne_qc_setting_right=$_SESSION['opne_qc_setting_right'];
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>產品設定</title>

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
                                            <!-- 上方左欄 start -->
                                            <p class="text-muted font-13 m-b-30">
                                            </p>
                                            <div class="row">
                                                <div class="col-md-6 col-sm-6 col-xs-6">
                                            <?php if ($d_id ==""){ 
                                                } else { ?>
                                            
                                                    <div class="x_panel">
                                                        <div class="x_title">
                                                        <?php
                                                            if($ProcessNo!=""){ ?>
                                                                <h2>製程：<?= $ProcessNo ?>
                                                                <?php switch($QC_Type){
                                                                        case "in":
                                                                            echo '<small>進料檢</small>';
                                                                        break;
                                                                        case "out":
                                                                            echo '<small>出貨檢</small>';
                                                                        break;
                                                                        case "all":
                                                                            echo '<small>進 / 出貨檢</small>';
                                                                        break; } ?>
                                                            <?php } ?>
                                                                </h2>
                                                            <!-- <ul class="nav navbar-right panel_toolbox">
                                                                <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                                                </li>
                                                            </ul> -->
                                                            <div class="clearfix"></div>
                                                        </div>
                                                        <div class="x_content">
                                                            <p class="text-muted font-13 m-b-30">
                                                            </p>
                                                            <form action="../../src/store/_updateQC_id.php" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                                                <div>
                                                                    <input type="hidden" id="QC_Id" name="QC_Id"
                                                                            value="<?= $QC_Id ?>"><!--KEY值!-->
                                                                            
                                                                </div>
                                                                    <?php if($QC_TOTAL==""){ ?>
                                                                        <div class="item form-group">
                                                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ProcessNo">製程編號 <span class="required">*</span>
                                                                            </label>
                                                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                                                <input id="ProcessNo" class="" name="ProcessNo" data-validate-length-range="2"
                                                                                required minlength="0" maxlength="10" size="6" style="font-size:15px"
                                                                                data-validate-words="1" required="required" type="text"
                                                                                class="form-control col-md-7 col-xs-12">
                                                                            </div>
                                                                        </div>
                                                                        <?php if($ProcessNo_list!=NULL) { ?>
                                                                            <div class="item form-group">
                                                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ProcessNo_option">已建立製程 <span class="required">*</span>
                                                                                </label>
                                                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                                                    <select id="ProcessNo_option" name="ProcessNo_option">   
                                                                                        <?php if(isset($ProcessNo)) { ?>
                                                                                            <option value="0">請選擇</option>
                                                                                            <!-- <option value="<?=$ProcessNo?>"><?=$ProcessNo?></option> -->
                                                                                            <!-- <hr> -->
                                                                                        <?php foreach ($ProcessNo_list as $ProcessNo_list) { ?>
                                                                                            <option value="<?= $ProcessNo_list['ProcessNo'] ?>"><?= $ProcessNo_list['ProcessNo'] ?></option>
                                                                                        <?php }} else { ?>
                                                                                            <option value="0">請選擇</option>
                                                                                        <?php foreach ($ProcessNo_list as $ProcessNo_list) { ?>
                                                                                            <option value="<?= $ProcessNo_list['ProcessNo'] ?>"><?= $ProcessNo_list['ProcessNo'] ?></option>  
                                                                                        <?php }} ?>
                                                                                    </select><small>優先</small>
                                                                                </div>
                                                                            </div>
                                                                        <?php } ?>
                                                                        <div class="item form-group">
                                                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="QC_Type">報告種類 <span class="required">*</span>
                                                                            </label>
                                                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                                                <select id="QC_Type" name="QC_Type">
                                                                                    <?php if(isset($QC_Type)) { ?>
                                                                                        <option value="<?=$QC_Type?>">
                                                                                        <?php switch($QC_Type){
                                                                                            case 'in':
                                                                                                echo '進料檢';
                                                                                            break;
                                                                                            case 'out':
                                                                                                echo '出貨檢';
                                                                                            break;
                                                                                            case 'all':
                                                                                                echo '全種類';
                                                                                            break;} ?>
                                                                                        </option>
                                                                                        <hr>
                                                                                        <option value="in">進料檢</option>
                                                                                        <option value="out">出貨檢</option>
                                                                                        <option value="all">全種類</option>
                                                                                    <?php } else { ?>
                                                                                        <option value="in">進料檢</option>
                                                                                        <option value="out">出貨檢</option>
                                                                                        <option value="all">全種類</option>
                                                                                        <?php } ?>
                                                                                </select>
                                                                            </div>
                                                                        </div>
                                                                        <div class="item form-group">
                                                                            <label class="control-label col-md-3 col-sm-3 col-xs-12">
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
                                                                            <button name="QC_TOTAL_Del" type="submit" class="btn btn-primary btn-danger">刪除製程</button>
                                                                            &emsp;&emsp;
                                                                            <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                                            &emsp;&emsp;
                                                                            <button name="newQC_setting" type="submit" class="btn btn-primary">新增新製程</button>
                                                                        </div>
                                                                    <?php
                                                                    }
                                                                    ?>
                                                            </form>
                                                        </div>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                            <!-- 上方左欄 end -->
                                            
                                            <?php if($opne_qc_setting_right=="OK"){ ?>
                                            <!-- 上方右欄 start -->
                                            <div class="row">
                                                <div class="col-md-6 col-sm-6 col-xs-6">
                                                    <?php if ($d_id ==""){ } else { ?>
                                                    <div class="x_panel">
                                                        <div class="x_title">
                                                            <!-- <h2></h2> -->
                                                            <!-- <ul 
                                                            class="clearfix"></div> -->
                                                        </div>
                                                        <div class="x_content">
                                                            <p class="text-muted font-13 m-b-30">
                                                            </p>
                                                            <form action="../../src/store/_updateQC_id_Scope.php" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                                                <div>
                                                                    <input type="hidden" id="QC_Scope_id" name="QC_Scope_id"
                                                                            value="<?= $QC_Scope_id ?>"><!--KEY值!-->
                                                                </div>
                                                                <div class="item form-group">
                                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="QC_List_No">部位編號 <span class="required">*</span>
                                                                    </label>
                                                                    <div class="col-md-6 col-sm-6 col-xs-12">
                                                                        <input id="QC_List_No" class="" name="QC_List_No" data-validate-length-range="2"
                                                                        required minlength="1" maxlength="8" size="8"
                                                                        data-validate-words="1" required="required" type="text"
                                                                        class="form-control col-md-7 col-xs-12" value="<?= $QC_List_No ?>">                             
                                                                    </div>
                                                                </div>
                                                                <div class="item form-group">
                                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="QC_Tool">量測工具 <span class="required">*</span>
                                                                    </label>
                                                                    <div class="col-md-6 col-sm-6 col-xs-12">
                                                                        <select name="QC_Tool" id="QC_Tool">
                                                                            <option value="0">請選擇</option>
                                                                            <?php foreach($qc_tool_list as $qc_tool_list){ ?>
                                                                              <option value="<?= $qc_tool_list['QC_Tool_List_id']?>"
                                                                                <?php if($QC_Tool == $qc_tool_list['QC_Tool_List_id']){echo "selected" ?>
                                                                              ><?= $qc_tool_list['QC_Tool'] ?></option>
                                                                            <?php 
                                                                            } else { ?>
                                                                                <option value="<?= $qc_tool_list['QC_Tool_List_id']?>"><?= $qc_tool_list['QC_Tool'] ?></option>
                                                                            <?php };} ?>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="item form-group">
                                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="QC_Scope">圖面尺寸 <span class="required">*</span>
                                                                    </label>
                                                                    <div class="col-md-6 col-sm-6 col-xs-12">
                                                                        <input id="QC_Scope" class="" name="QC_Scope" data-validate-length-range="2"
                                                                        required minlength="1" maxlength="8" size="8"
                                                                        data-validate-words="1" required="required" type="text"
                                                                        class="form-control col-md-7 col-xs-12" value="<?= $QC_Scope ?>">                             
                                                                    </div>
                                                                </div>
                                                                <div class="item form-group">
                                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="QC_S_Scope">特殊尺寸 <span class="required">*</span>
                                                                    </label>
                                                                    <div class="col-md-6 col-sm-6 col-xs-12">
                                                                    <?php
                                                                        if($QC_S_Scope==1){ ?>
                                                                            <input id="QC_S_Scope" checked class="" value="1" name="QC_S_Scope" data-validate-length-range="2"
                                                                            data-validate-words="1" type="checkbox"
                                                                            class="form-control col-md-7 col-xs-12">
                                                                    </div>
                                                                </div>
                                                                        <?php } else{ ?>
                                                                                <input id="QC_S_Scope" class="" value="1" name="QC_S_Scope" data-validate-length-range="2"
                                                                                data-validate-words="1" type="checkbox"
                                                                                class="form-control col-md-7 col-xs-12">
                                                                    </div>
                                                                </div>
                                                                <div class="item form-group">
                                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Upper_Limit">上限 <span class="required">*</span>
                                                                    </label>
                                                                    <div class="col-md-6 col-sm-6 col-xs-12">
                                                                        <input id="Upper_Limit" class="" name="Upper_Limit" data-validate-length-range="2"
                                                                        data-validate-words="1" required="required" type="text"
                                                                        required minlength="1" maxlength="8" size="8"
                                                                        class="form-control col-md-7 col-xs-12" value="<?= $Upper_Limit ?>">                             
                                                                    </div>
                                                                </div><div class="item form-group">
                                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Lower_Limit">下限 <span class="required">*</span>
                                                                    </label>
                                                                    <div class="col-md-6 col-sm-6 col-xs-12">
                                                                        <input id="Lower_Limit" class="" name="Lower_Limit" data-validate-length-range="2"
                                                                        required minlength="1" maxlength="8" size="8"
                                                                        data-validate-words="1" required="required" type="text"
                                                                        class="form-control col-md-7 col-xs-12" value="<?= $Lower_Limit ?>">                             
                                                                    </div>
                                                                </div>
                                                                        <?php } ?>                    
                                                                <div class="item form-group">
                                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                                    </label>
                                                                    <button name="resetpSetting" type="submit" class="btn btn-primary">清除</button>
                                                                    <!-- <button name="QC_TOTAL_Del" type="submit" class="btn btn-primary btn-danger">刪除</button> -->
                                                                    &emsp;&emsp;
                                                                    <button name="QC_Scope_update" type="submit" class="btn btn-primary">更新</button>
                                                                    <!-- <button name="QC_TOTAL_Del_dbCHECK" type="submit" class="btn btn-primary btn-danger">確認刪除</button> -->
                                                            </form>
                                                        </div>
                                                    </div>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                            <!-- 上方右欄 end -->
                                            <?php } else {} ?>


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
                                                                                        Yes
                                                                                <?php } else{ ?>
                                                                                        No
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
                                                    <!-- <th>id</th> -->
                                                    <th>料號</th>
                                                    <th>規格</th>
                                                    <th>客戶</th>
                                                    <th>已建立製程</th>
                                                    <th></th>

                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                foreach ($ALL_Sce as $ALL_Sce) {
                                                    ?>
                                                    <tr>
                                                        <!-- type=hidden -->
                                                        <!-- <td name="d_id">
                                                            <?= $ALL_Sce['d_id'] ?>
                                                        </td> -->
                                                        <td name="D_Setting_Id">
                                                            <?= $ALL_Sce['D_Setting_Id'] ?>
                                                        </td>
                                                        <td name="Drawing_No">
                                                            <?= $ALL_Sce['Drawing_No'] ?>
                                                        </td>
                                                        <td name="Client_Name">
                                                            <?= $ALL_Sce['Client_Name'] ?>
                                                        </td>
                                                        <td name="ProcessNo">
                                                            <?= $ALL_Sce['ProcessNo'] ?>
                                                        </td>
                                                        <td>
                                                            <a
                                                                href="../../src/store/_updateQCSetting.php?d_id=<?= $ALL_Sce['d_id'] ?>&D_Setting_Id=<?= $ALL_Sce['D_Setting_Id'] ?>&ProcessNo=<?= $ALL_Sce['ProcessNo'] ?>">
                                                                <input type="button" name="updateQCSetting" class="btn btn-warning btn-xs update"
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
