<?php

session_start();

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

$msg=null;

//TG
$conn = new DBConnection();
$admins = $conn->getAll("SELECT * FROM `schedule` WHERE type = 3");

@$odate   = $_SESSION['odate'];
@$bom     = $_SESSION['bom'];
@$cs_name = $_SESSION['cs_name'];
@$lname   = $_SESSION['lname'];
@$s_mod   = $_SESSION['s_mod'];
@$qty     = $_SESSION['qty'];
@$machine = $_SESSION['machine'];
@$s_no    = $_SESSION['s_no'];
@$s_id    = $_SESSION['id'];
@$other   = $_SESSION['other'];

//查詢TG
$result = null;
if (isset($_POST['btn_go_admins'])) {
    $find = $_POST["sreach_admins"];

    $stmt = $db->prepare("select * from schedule where type=3 order by machine,s_no");
    $stmt->bindValue(':find', $find, PDO::PARAM_INT);
    $result = $stmt->execute();
}

?>

<!-- <meta http-equiv="refresh" content="20; url=schedule_TG.php" >
@header("refresh:0.1;url=schedule_TG.php");
url=main.php這裡改為url=main.php?page=XX就好囉
PS.請依你的程式修改page這個名稱並將XX改為你當下的頁面變數 -->
<!-- <meta http-equiv="refresh" content="5; url=schedule_TG.php?id=<?= $_GET['id']?>" > -->


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
#window-container{
    display: none;
    position: fixed;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}
       
#window-pop{
    background: white;
    width:40%;
    z-index: 1;
    margin: 12% auto;
    overflow: auto;
    border-radius: 20px;
}
        
.window-content {
    width: auto;
    height: 300px;
    line-height: 500px;
    overflow: auto;
    text-align: center;
}

span {
    display: inline-block;
    vertical-align: middle;
    line-height: normal;
}
    </style>

    <script>
        function customizeWindowEvent() {
            var popup_window = document.getElementById("window-container");

            popup_window.style.display = "block";

            window.onclick = function close(e) {
                if (e.target == popup_window) {
                    popup_window.style.display = "none";
                }
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
                            <h3>齒研紀錄</h3>
                        </div>
                        <form method="POST" action="schedule.php?id=<?= $_GET['id']?>">
                        <div class="title_right">
                            <div class="col-md-5 col-sm-5 col-xs-12 form-group pull-right top_search">
                                <div class="input-group">
                                    <input name="sreach_admins" type="text" class="form-control" placeholder="搜索名稱...">
                                    <span class="input-group-btn">
                                        <input name="btn_go_admins" class="btn btn-default" type="submit" value="Go!">
                                    </span>
                                </div>
                            </div>
                        </div>
                        </form>
                    </div>
                    <div class="clearfix"></div>

                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <!-- <div class="x_panel">
                                <div class="x_title">
                                    <h2><?= $msg ?></h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                        </li>
                                        <li><a class="close-link"><i class="fa fa-close"></i></a>
                                        </li>
                                    </ul>

                                    <div class="clearfix">
                                        <h2>齒研資料修改</h2>
                                    </div>
                                </div>
                                <div class="x_content">

                                    <form method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                    <span class="section"></span>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="s_no">順序 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="s_no" class="form-control col-md-7 col-xs-12" value="<?= $s_no ?>" data-validate-length-range="6" data-validate-words="1" name="s_no" placeholder="" required="required" type="text">
                                            </div>
                                        </div>

                                        <input type="hidden" id="odate" name="odate" value="<?= $odate ?>">
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="odate">交期 <span class="required"></span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="odate" class="form-control col-md-7 col-xs-12" value="<?= $odate ?>" data-validate-length-range="6" data-validate-words="1" name="odate" required="required" type="text">
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="bom">BOM <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="bom" class="form-control col-md-7 col-xs-12" value="<?= $bom ?>" data-validate-length-range="6" data-validate-words="1" name="bom" placeholder="B-1110101011" required="required" type="text">
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label for="cs_name" class="control-label col-md-3">客戶 <span class="required">*</span></label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="cs_name" name="cs_name" value="<?= $cs_name ?>" data-validate-length="6,8" class="form-control col-md-7 col-xs-12" required="required">
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="lname">產編 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="lname" class="form-control col-md-7 col-xs-12" value="<?= $lname ?>" data-validate-length-range="6" data-validate-words="1" name="lname" placeholder="6003002512" required="required" type="text">
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="s_mod">模數齒數 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="s_mod" class="form-control col-md-7 col-xs-12" value="<?= $s_mod ?>" data-validate-length-range="6" data-validate-words="1" name="s_mod" placeholder="M2T80" required="required" type="text">
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="qty">數量 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="qty" class="form-control col-md-7 col-xs-12" value="<?= $qty ?>" data-validate-length-range="6" data-validate-words="1" name="qty" placeholder="500" required="required" type="text">
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="machine">機台 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="machine" class="form-control col-md-7 col-xs-12" value="<?= $machine ?>" data-validate-length-range="6" data-validate-words="1" name="machine" placeholder="KAPP" required="required" type="text">
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="other">備註 <span class="required"></span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="other" class="form-control col-md-7 col-xs-12" value="<?= $other ?>" data-validate-length-range="6" data-validate-words="1" name="other" placeholder="1E" required="required" type="text">
                                            </div>
                                        </div>
                                        

                                        <div class="ln_solid"></div>
                                        <div class="form-group">
                                            <div class="col-md-6 col-md-offset-3">
                                                <button type="submit" name="resetTG" class="btn btn-primary">取消 / 清除</button>
                                                <button id="send" name="updataTG" type="submit" class="btn btn-success">送出</button>
                                                <button id="send" name="newTG" type="submit" class="btn btn-success">新增</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div> -->
                        </div>
                    </div>

                    <!-- TG -->
                    <form method="POST" action="">
                        <div class="row">

                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <h2>齒研紀錄總覽</h2>
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

                                        <table id="datatable-buttons" class="table table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>日期</th>
                                                    <th>客戶</th>
                                                    <th>BOM</th>
                                                    <th>圖號</th>
                                                    <th>模數</th>
                                                    <th>齒數</th>
                                                    <th>齒幅</th>
                                                    <th>總數</th>
                                                    <th>完成數量</th>
                                                    <th>未完成數量</th>
                                                    <th>NG數量</th>
                                                    <th>校機人員</th>
                                                    <th>校機時間</th>
                                                    <th>生產人員</th>
                                                    <th>生產時間</th>
                                                    <th>完工</th>
                                                    <th>機型</th>
                                                    <th>備註</th>
                                                    <th></th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <?php
                                                foreach ($admins as $admins) {
                                                ?>
                                                    <tr>
                                                        <td style="font-size:smaller"><?= $admins['s_no'] ?></td>
                                                        <td style="width: 45px;font-size:smaller"><?= $admins['odate'] ?></td>
                                                        <td style="width: 100px;font-size:1%"><?= $admins['bom'] ?></td>
                                                        <td style="width: 70px;font-size:smaller"><?= $admins['cs_name'] ?></td>
                                                        <td style="width: 150px;font-size:smaller"><?= $admins['lname'] ?></td>
                                                        <td style="width: 70px;font-size:smaller"><?= $admins['s_mod'] ?></td>
                                                        <td style="width: 50px;font-size:smaller"><?= $admins['qty'] ?></td>
                                                        <td style="width: 50px;font-size:smaller"><?= $admins['machine'] ?></td>
                                                        <td style="width: 60px;font-size:smaller>"><?= $admins['change_name'] ?></td>
                                                        <td style="font-size:smaller>"><?= $admins['other'] ?></td>
                                                        <td style="width: 130px;font-size:1%"><?= $admins['change_time'] ?></td>
                                                        <td style="width: 130px;font-size:1%">
                                                            <!-- <a href="../../src/store/_updateTG.php?s_id=<?= $admins['id'] ?>&id=<?= $_GET['id']?>"><input type="button" name="updateTG" class="btn btn-warning btn-xs update" value="更新"></a> -->
                                                            <a href="../../src/store/_deleteTG.php?s_id=<?= $admins['id'] ?>&id=<?= $_GET['id']?>"><input type="button" name="deleteTG" class="btn btn-danger btn-xs delete" value="刪除"></a>
                                                        
                                                            <div id="window-container">
                                                                <div id="window-pop">
                                                                    <div class="window-content">
                                                                    <table id="datatable-buttons" class="table table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>日期</th>
                                                    <th>客戶</th>
                                                    <th>BOM</th>
                                                    <th>圖號</th>
                                                    <th>模數</th>
                                                    <th>齒數</th>
                                                    <th>齒幅</th>
                                                    <th>總數</th>
                                                    <th>完成數量</th>
                                                    <th>未完成數量</th>
                                                    <th>NG數量</th>
                                                    <th>校機人員</th>
                                                    <th>校機時間</th>
                                                    <th>生產人員</th>
                                                    <th>生產時間</th>
                                                    <th>完工</th>
                                                    <th>機型</th>
                                                    <th>備註</th>
                                                    <th></th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <?php
                                                foreach ($admins as $admins) {
                                                ?>
                                                    <tr>
                                                        <td style="font-size:smaller"><?= $admins['s_no'] ?></td>
                                                        <td style="width: 45px;font-size:smaller"><?= $admins['odate'] ?></td>
                                                        <td style="width: 100px;font-size:1%"><?= $admins['bom'] ?></td>
                                                        <td style="width: 70px;font-size:smaller"><?= $admins['cs_name'] ?></td>
                                                        <td style="width: 150px;font-size:smaller"><?= $admins['lname'] ?></td>
                                                        <td style="width: 70px;font-size:smaller"><?= $admins['s_mod'] ?></td>
                                                        <td style="width: 50px;font-size:smaller"><?= $admins['qty'] ?></td>
                                                        <td style="width: 50px;font-size:smaller"><?= $admins['machine'] ?></td>
                                                        <td style="width: 60px;font-size:smaller>"><?= $admins['change_name'] ?></td>
                                                        <td style="font-size:smaller>"><?= $admins['other'] ?></td>
                                                        <td style="width: 130px;font-size:1%"><?= $admins['change_time'] ?></td>
                                                        <td style="width: 130px;font-size:1%">
                                                            <!-- <a href="../../src/store/_updateTG.php?s_id=<?= $admins['id'] ?>&id=<?= $_GET['id']?>"><input type="button" name="updateTG" class="btn btn-warning btn-xs update" value="更新"></a> -->
                                                            <a href="../../src/store/_deleteTG.php?s_id=<?= $admins['id'] ?>&id=<?= $_GET['id']?>"><input type="button" name="deleteTG" class="btn btn-danger btn-xs delete" value="刪除"></a>
                                                        
                                    

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
                                                                        <a onclick="customizeWindowEvent()" href="../../src/store/_updateTG.php?s_id=<?= $admins['id'] ?>&id=<?= $_GET['id']?>"><input type="button" name="updateTG" class="btn btn-warning btn-xs update" value="更新"></a>
                                                        
                                                                        <!-- <a onclick="customizeWindowEvent()">修改</a> -->

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