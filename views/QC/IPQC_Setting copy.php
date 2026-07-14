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

@$ALL_Sce = $conn->getAll("SELECT bom_ing.bom,bom_ing.sqty,d_setting.d_id,d_setting.D_Setting_Id, bom.Client_Name,
qc.ProcessNo FROM bom left join d_setting on bom.d_id=d_setting.d_id LEFT join bom_ing on bom_ing.bom=bom.bom left join qc on qc.d_id=d_setting.d_id
ORDER BY bom.bom");

@$d_id = $_SESSION['d_id'];
@$D_Setting_Id = $_SESSION['D_Setting_Id'];
@$Drawing_No = $_SESSION['Drawing_No']; //料號
@$Spec_No = $_SESSION['Spec_No']; //規格
@$Client_Name = $_SESSION['Client_Name']; //客戶名稱

@$List = $conn->getAll("SELECT * FROM `QC`");
@$qc_tool_list = $conn->getAll("SELECT * FROM `qc_tool_list`");

if($QC_Id!=""){
@$qc_result_list = $conn->getAll("SELECT qc_scope.QC_Id,qc_tool_list.QC_Tool,qc_scope.QC_List_No,qc_scope.QC_Scope_id,qc_scope.QC_Scope,qc_scope.QC_S_Scope,qc_scope.Upper_Limit,qc_scope.Lower_Limit FROM `qc_scope` LEFT JOIN `qc_tool_list` ON qc_scope.QC_Tool=qc_tool_list.QC_Tool_List_id WHERE qc_scope.QC_Id=$QC_Id order by QC_List_No");
};


@$QC_TOTAL_Del=$_SESSION['QC_TOTAL_Del'];

if($D_Setting_Id!=""){
@$ProcessNo_list = $conn->getAll("SELECT ProcessNo FROM qc WHERE d_id=$d_id AND NOT(d_id IS NULL) ORDER BY ProcessNo");
};

@$opne_qc_setting_right=$_SESSION['opne_qc_setting_right'];

//2024.03.28 開始
@$IPQC_LEFT_LIST=$conn->getall("SELECT bom.bom,qc.ProcessNo,qc.QC_Id,QC_Scope.QC_List_No,
QC_Scope.QC_Scope,QC_Scope.QC_S_Scope,QC_Scope.Upper_Limit,QC_Scope.Lower_Limit
FROM bom 
LEFT join bom_ing on bom_ing.bom=bom.bom
LEFT join qc ON qc.d_id=bom.d_id
LEFT join qc_scope ON qc_scope.QC_Id=qc.QC_Id
WHERE bom.bom='B-1120511012' AND qc.ProcessNo='170'");



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
                                                        <h2>進料檢</h2>
                                                        <?php
                                                            if($ProcessNo!=""){ ?>
                                                                <h2>
                                                                <input id="bom" class="" name="bom" data-validate-length-range="2"
                                                                        required minlength="0" maxlength="10" size="6" style="font-size:15px"
                                                                        data-validate-words="1" required="required" type="text"
                                                                        class="form-control col-md-7 col-xs-12">
                                                                <input id="ProcessNo" class="" name="ProcessNo" data-validate-length-range="2"
                                                                        required minlength="0" maxlength="10" size="6" style="font-size:15px"
                                                                        data-validate-words="1" required="required" type="text"
                                                                        class="form-control col-md-7 col-xs-12">
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
                                                                                            class="form-control col-md-7 col-xs-12">
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
                                            
                                            <!-- 中欄 start -->
                                                <table class="table table-striped table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>尺寸 / 編號</th>
                                                        <?php
                                                        $c_ipqc=count($IPQC_LEFT_LIST);
                                                        // $ii=10;
                                                        for ($i = 1; $i <= $c_ipqc; $i++) { //抬頭 1-10
                                                        ?>
                                                            <th><?= $i ?></th> 
                                                        <?php } ?>
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
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            <!-- 中欄 end -->


                                            
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
