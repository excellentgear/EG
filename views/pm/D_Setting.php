<?php
// 2024.03.13 表格RWD不ok,其他沒問題
session_start();

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';


//料號
@$conn = new DBConnection();
@$D_ALL = $conn->getAll("SELECT * FROM `d_setting` ORDER BY Drawing_No");

@$d_id           = $_SESSION['d_id'];
@$D_Setting_Id   = $_SESSION['D_Setting_Id'];
@$Drawing_No     = $_SESSION['Drawing_No']; //品名
@$Spec_No        = $_SESSION['Spec_No']; //規格
@$Client_Name    = $_SESSION['Client_Name']; //客戶名稱


// @$BOM_list = $conn->getAll("SELECT Bom,D_Setting_Id FROM `Bom`");

@$d_setting_Del=$_SESSION['d_setting_Del'];

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

<style>
.rwd-table {
　background: #fff;
　overflow: hidden;
}

.rwd-table tr:nth-of-type(2n){
　background: #eee;
}
.rwd-table th,
.rwd-table td {
　margin: 0.5em 1em;
}
.rwd-table {
　min-width: 100%;
}

.rwd-table th {
　display: none;
}

.rwd-table td {
　display: block;
}

.rwd-table td:before {
　content: attr(data-th) " : ";
　font-weight: bold;
　width: 6.5em;
　display: inline-block;
}

.rwd-table th, .rwd-table td {
　text-align: left;
}

.rwd-table th, .rwd-table td:before {
　color: #D20B2A;
　font-weight: bold;
}

@media (min-width: 480px) {
.rwd-table td:before {
　display: none;
}
.rwd-table th, .rwd-table td {
　display: table-cell;
　padding: 0.25em 0.5em;
}
.rwd-table th:first-child,
.rwd-table td:first-child {
　padding-left: 0;
}
.rwd-table th:last-child,
.rwd-table td:last-child {
　padding-right: 0;
}
.rwd-table th,
.rwd-table td {
　padding: 1em !important;
}
}
</style>

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
                                    更新成功
                                    </div>";
                                } else if($_GET['message']!="success"){
                                    $var=$_GET['message'];
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
                    
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
    
                                <div class="x_title">
                                    <h2>料號設定 <small>直接新增或點選[更新]後送出修改</small></h2>
                                    <div class="clearfix"></div>
                                </div>
    
                                <div class="x_content">
                                    <p class="text-muted font-13 m-b-30">
                                    </p>
                                    <form action="../../src/store/_settingD.php?&d_id=<?= $d_id ?>" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                    <input id="d_id" name="d_id" value="<?= $d_id ?>" type="hidden">
                                        <!-- 新增料號 -->
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="D_Setting_Id"> 料號 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                            <input id="D_Setting_Id" 
                                                <?php
                                                if(!empty($_GET['VS'])) {
                                                        echo "readonly";
                                                } 
                                                ?>
                                                class="D_Setting_Id" value="<?= $D_Setting_Id ?>" data-validate-length-range="2" data-validate-words="1" name="D_Setting_Id" required="required" type="text">
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Drawing_No"> 品名 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="Drawing_No" name="Drawing_No" value="<?= $Drawing_No ?>" data-validate-length-range="2" data-validate-words="1" name="Spec_No" required="required" type="text">
                                            </div>
                                        </div>
    
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Spec_No"> 規格 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="Spec_No" name="Spec_No" value="<?= $Spec_No ?>" data-validate-length-range="2" data-validate-words="1" name="Spec_No" required="required" type="text">
                                            </div>
                                        </div>
    
    
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name"> 客戶名稱 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="Client_Name" class="Client_Name" value="<?= $Client_Name ?>" data-validate-length-range="2" data-validate-words="1" name="Client_Name" required="required" type="text">
                                            </div>
                                        </div>
    
    
                                        <!-- <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="BOM"> 設定 BOM <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="BOM" class="BOM" value="<?= $BOM ?>" data-validate-length-range="2" data-validate-words="1" name="BOM" required="required" type="text">
                                            </div>
                                        </div> -->
                                        <div class="ln_solid"></div>
                                        <div class="form-group">
                                            <div class="col-md-6 col-md-offset-3">
                                                <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                        <?php
                                        if ($d_setting_Del=='9'){ ?>
                                                <button id="send" name="d_setting_Del_dbCHECK" type="submit" class="btn btn-danger">確認刪除</button>
                                        <?php } else { ?>
                                                <button id="send" name="DSetting_UpDate" type="submit" class="btn btn-success">送出</button>
                                        <?php } ?>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="row">

                    <div class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>料號總覽 
                                    <!-- <small>Event</small> -->
                                </h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                    </li>
                                    <!-- <li class="dropdown">
                                        <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false"><i class="fa fa-wrench"></i></a>
                                        <ul class="dropdown-menu" role="menu">
                                            <li><a href="#">Settings 1</a>
                                            </li>
                                            <li><a href="#">Settings 2</a>
                                            </li>
                                        </ul>
                                    </li> -->
                                    <li><a class="close-link"><i class="fa fa-close"></i></a>
                                    </li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">

                                <p class="text-muted font-13 m-b-30">

                                </p>
                                <!-- table table-striped table-bordered -->
                                
                                <table id="datatable-buttons" class="rwd-table table-striped">
                                    <thead>
                                    <tr>
                                        <th>料號</th>
                                        <th>品名</th>
                                        <!-- <th>規格</th> -->
                                        <!-- <th>英文品名</th> -->
                                        <th>客戶</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    foreach ($D_ALL as $D_ALL){
                                    ?>
                                    <tr>
                                        <td name="D_Setting_Id"> <?= $D_ALL['D_Setting_Id'] ?></td>
                                        <td name="Drawing_No"> <?= $D_ALL['Drawing_No'] ?></td>
                                        <!-- <td name="Spec_No"> <?= $D_ALL['Spec_No'] ?></td> -->
                                        <!-- <td name="E_Drawing_No"> <?= $D_ALL['E_Drawing_No'] ?></td> -->
                                        <td name="Client_Name"> <?= $D_ALL['Client_Name'] ?></td>
                                        <td>
                                            <a href="../../src/store/_updateDrawing.php?d_id=<?= $D_ALL['d_id'] ?>"><input type="button" name="updateDrawing" class="btn btn-warning btn-xs update" value="更新"></a>
                                            <a href="../../src/store/_deleteDrawing.php?d_id=<?= $D_ALL['d_id'] ?>"><input type="button" name="deleteDrawing" class="btn btn-danger btn-xs delete" value="刪除"></a>
                                        </td>
                                    </tr>
                                    <?php
                                   }
                                    ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
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
