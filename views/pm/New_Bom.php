<?php
session_start();
include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

//測試掃條碼用 1/2
if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage']="../../views/pm/BOM_Setting.php?in=999&bom=".$_GET['bom']."&pno=".$_GET['pno'];
    header("Location:../../index.php?bom=".$_GET['bom']."&pno=".$_GET['pno']); //返回登入頁
    exit();
}


//料號
@$conn = new DBConnection();

@$d_id           = $_SESSION['d_id'];
@$BOM            = $_SESSION['bom'];
@$Spec_No        = $_SESSION['Spec_No']; //規格
@$Client_Name    = $_SESSION['Client_Name']; //客戶名稱
@$sqty           = $_SESSION['sqty'];   
// @$old_sqty       = $_SESSION['old_sqty'];   //暫存舊有數量

@$bt             = $_GET['bt'];//判定查詢後更新資料

@$D_Setting_list = $conn->getAll("SELECT * FROM bom ORDER BY bom");

@$show_del=$_SESSION['show_del'];
@$bom_Del=$_SESSION['bom_Del'];

@$pno=127;

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>BOM 設定</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
</head>


<body class="nav-sm">
    <div class="container body">
        <div class="main_container">

            <!-- side and top bar include -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>
            <!-- /side and top bar include -->

            <!-- page content -->
            <div class="right_col" role="main">
                <div class="page-title">
                    <h2>BOM 登錄</h2>
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
                    </div>
                </div>
                <div class="clearfix"></div>

                <div class="row">
                    <div class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                
                                <div class="clearfix">
                                </div>
                            </div>
                            <div class="x_content">

                                <form action="../../src/store/_settingNewBom.php?bt='<?= $bt ?>'" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                    <!-- 設定BOM -->
                                       


                                    <div class="item form-group">
                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="BOM"> 設定 BOM
                                        </label>
                                        <div class="col-md-6 col-sm-6 col-xs-12">
                                            <input id="BOM" class="BOM" value="<?= $BOM ?>" required minlength="12" maxlength="12" name="BOM" required="required" 
                                            type="text" size="12">
                                            <small>格式：B-1130131001</small>
                                        </div>
                                    </div>
                                    <div class="item form-group">
                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="d_id"> 料號
                                        </label>
                                        <div class="col-md-9 col-sm-9 col-xs-12">
                                            <input id="d_id" class="d_id" value="<?= $d_id ?>" required name="d_id" required="required" 
                                            type="text" >
                                        </div>
                                    </div>
                                    <div class="item form-group">
                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name"> 客戶 <span class="required">*</span>
                                        </label>
                                        <div class="col-md-6 col-sm-6 col-xs-12">
                                            <input id="Client_Name" class="Client_Name" value="<?= $Client_Name ?>" data-validate-length-range="2" 
                                            data-validate-words="1" name="Client_Name" required="required" size="12" type="text" <?php if($_GET['bt']=='bcb'){ echo 'readonly'; }?>>
                                        </div>
                                    </div>
                                    <div class="item form-group">
                                        <label class="control-label col-md-3 col-sm-3 col-xs-12" for="sqty"> 訂單數 <span class="required">*</span>
                                        </label>
                                        <div class="col-md-6 col-sm-6 col-xs-12">
                                            <input id="sqty" class="sqty" value="<?= $sqty ?>" required minlength="12" maxlength="12" name="sqty" 
                                            required="required" type="text" size="12">
                                        </div>
                                    </div>
                                               
                                    <div class="ln_solid"></div>
                                    <div class="form-group">
                                        <?php
                                        if($bom_Del==9){ ?>
                                        <div class="col-md-6 col-md-offset-3">
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                </label>
                                                <button name="bom_Del_dbCHECK" type="submit" class="btn btn-primary btn-danger">確認刪除</button>
                                                &emsp;&emsp;
                                                <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                            </div>
                                        <?php
                                        } else if($BOM!="") { ?>
                                        <div class="col-md-6 col-md-offset-2">
                                            <div class="item form-group">
                                                <button name="bom_Del" type="submit" class="btn btn-danger">刪除</button>
                                                <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                <button name="BOMSetting_UpDate" type="submit" class="btn btn-success">更新資料</button>
                                                <?php if($BOM_set_list!=NULL) { ?>
                                                &emsp;
                                                <button name="BOMSetting_checkBom" type="submit" class="btn btn-warning">查詢</button>
                                                <?php } ?>
                                            <img src="https://chart.googleapis.com/chart?chs=100x100&cht=qr&chl=http%3A%2F%2F192.168.2.128%2FEGsystem%2Fviews%2FQC%2Fbom_Setting.php?bom=<?= $BOM ?>&pno=<?= $pno ?>&choe=UTF-8"/>
                                            </div>
                                        <?php
                                        } else { ?>
                                        <div class="col-md-6 col-md-offset-3">
                                            <div class="item form-group">
                                                <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                <button name="BOMSetting_UpDate" type="submit" class="btn btn-success">更新資料</button>
                                                <?php if($BOM_set_list!=NULL) { ?>
                                                &emsp;
                                                <button name="BOMSetting_checkBom" type="submit" class="btn btn-warning">查詢</button>
                                                <?php }  ?>
                                            </div>
                                        <?php } ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- BOM總覽 -->
                <form method="POST" action="">
                    <div class="row">

                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2>BOM總覽</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                        </li>

                                        <li><a class="close-link"><i class="fa fa-close"></i></a>
                                        </li>
                                    </ul>

                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">

                                <p class="text-muted font-13 m-b-30">

                                </p>
                                <!-- 呈現資料   -->


                                <table id="datatable-buttons" class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>BOM</th>
                                            <th>料號</th>
                                            <!-- <th>品名</th> -->
                                            <!-- <th>規格</th> -->
                                            <th>客戶</th>
                                            <th>數量</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($D_Setting_list as $D_Setting_list) {
                                        ?>
                                            <tr>
                                                <!-- <input type="hidden" name="D_Setting_Id" value="<?= $D_Setting_list['D_Setting_Id'] ?>"> -->
                                                <td name="bom"> <?= $D_Setting_list['bom'] ?></td>
                                                <td name="d_id"> <?= $D_Setting_list['d_id'] ?></td>
                                                <!-- <td name="Drawing_No"> <?= $D_Setting_list['Drawing_No'] ?></td> -->
                                                <!-- <td name="Spec_No"> <?= $D_Setting_list['Spec_No'] ?></td> -->
                                                <td name="Client_Name"> <?= $D_Setting_list['Client_Name'] ?></td>
                                                <td name="sqty"> <?= $D_Setting_list['sqty'] ?></td>
                                                <td>
                                                    <a href="../../src/store/_updateNewBom.php?b=<?= $D_Setting_list['bom'] ?>">
                                                    <input type="button" name="updateDrawing" class="btn btn-warning btn-xs update" value="設定"></a>
                                            </tr>
                                        <?php }
                                        ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
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
    <script src="https://code.highcharts.com/highcharts.js"></script>
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