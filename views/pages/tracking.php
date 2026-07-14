<?php

session_start();

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

$msg=null;

//TG
$conn = new DBConnection();
$admins = $conn->getAll("SELECT * FROM `tracking`");

// @$odate   = $_SESSION['odate'];
// @$bom     = $_SESSION['bom'];
// @$cs_name = $_SESSION['cs_name'];
// @$lname   = $_SESSION['lname'];
// @$s_mod   = $_SESSION['s_mod'];
// @$qty     = $_SESSION['qty'];
// @$machine = $_SESSION['machine'];
// @$s_no    = $_SESSION['s_no'];
// @$s_id    = $_SESSION['id'];
// @$other   = $_SESSION['other'];

//查詢TG
$result = null;
if (isset($_POST['btn_go_admins'])) {
    $find = $_POST["sreach_admins"];

    $stmt = $db->prepare("select * from tracking order by bom,lname");
    $stmt->bindValue(':find', $find, PDO::PARAM_INT);
    $result = $stmt->execute();
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
                            <h3>BOM進度</h3>
                        </div>
                    </div>
                    
                    <!-- TG -->
                    <form method="POST" action="">
                        <div class="row">

                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <h2></h2>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                            </li>
                                            <li><a class="close-link"><i class="fa fa-close"></i></a>
                                            </li>
                                        </ul>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">

                                        <!-- <p class="text-muted font-13 m-b-30">

                                        </p> -->

                                        <table id="datatable-buttons" class="table table-striped table-bordered">
                                            <thead>
                                                <tr>					
                                                		

                                                    <th>BOM</th>
                                                    <th>品號</th>
                                                    <th>更新日期</th>
                                                    <th>製程</th>
                                                    <th>廠商</th>
                                                    <th>數量</th>
                                                    <th>期限</th>
                                                    <!-- <th>料號/規格</th> -->
                                                    <!-- <th>備註一</th> -->
                                                    <th>備註</th>
                                                    <th>TG順序</th>
                                                    <th>生管</th>
                                                    <th>交期</th>
                                                    <th>客戶</th>
                                                    <th>數量</th>
                                                    <th>總製程</th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                    <th></th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                <?php
                                                foreach ($admins as $admins) {
                                                ?>
                                                    <tr>
                                                        <td style="width: 70px"><?= $admins['bom'] ?></td>
                                                        <td style="width: 45px"><?= $admins['lname'] ?></td>
                                                        <td style="font-size:1%"><?= $admins['update_p'] ?></td>
                                                        <td style="width: 60px"><?= $admins['process'] ?></td>
                                                        <td style="width: 60px"><?= $admins['firm'] ?></td>
                                                        <td style="width: 60px"><?= $admins['qty'] ?></td>
                                                        <td style="width: 60px"><input style="width:40px" type="text" placeholder=<?= $admins['deadline'] ?>></td>
                                                        <!-- <td style="width: 45px"><?= $admins['other'] ?></td> -->
                                                        <td><textarea style="height: 30px;width: 70px;resize: none;" ><?= $admins['note1'] ?></textarea></td>
                                                        <!-- <td style="font-size:smaller>"><?= $admins['note'] ?></td> -->
                                                        <td style="width: 45px"><?= $admins['TG_order'] ?></td>
                                                        <td style="width: 45px"><?= $admins['pc'] ?></td>
                                                        <td style="width: 45px"><?= $admins['pc'] ?></td>
                                                        <td style="width: 45px"><?= $admins['pc'] ?></td>
                                                        <td style="width: 45px"><?= $admins['pc'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no1'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no2'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no3'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no4'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no5'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no6'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no7'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no8'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no9'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no10'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no11'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no12'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no13'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no14'] ?></td>
                                                        <td style="width: 45px"><?= $admins['p_no15'] ?></td>
                                                        <td style="width: 210px;font-size:1%">
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