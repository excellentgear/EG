<?php
session_start();

if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage']="../../views/pm/OreadyReply_ForPm.php";
    header("Location:../../index.php"); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$userName      = $_SESSION['user_cname'];
@$id            = $_SESSION['id'];


@$conn = new DBConnection();

@$OreadyReply_list = $conn->getAll("SELECT DISTINCT vw_oreadyreply_list.`OreadyReply_id`,
vw_oreadyreply_list.`BOM`,
vw_oreadyreply_list.`Client_Name`,
vw_oreadyreply_list.`sqty`,
vw_oreadyreply_list.`ProcessNo`,
vw_oreadyreply_list.`MakerId`,
vw_vw_oreadyreply_forpm.oready_sqty_total,
vw_vw_oreadyreply_forpm.ng_sqty_total,
vw_vw_oreadyreply_forpm.Created_At_start,
vw_vw_oreadyreply_forpm.Created_At_end,
vw_oreadyreply_list.`ProcessName`
FROM vw_oreadyreply_list
LEFT JOIN vw_vw_oreadyreply_forpm 
on vw_vw_oreadyreply_forpm.OreadyReply_id=vw_oreadyreply_list.OreadyReply_id
ORDER BY vw_vw_oreadyreply_forpm.Created_At_end DESC");

@$BOM           =$_GET['BOM'];         
@$ProcessNo     =$_GET['ProcessNo'];   
@$MakerId       =$_GET['MakerId'];     
@$sqty          =$_GET['sqty'];
@$D_Setting_Id  =$_GET['dsi'];
@$Client_Name   =$_GET['c'];

@$pn            =$_SESSION['pn'];

@$PmOreadyReply_list = $conn->getAll("SELECT `BOM`,`oready_sqty`,date(`Created_At`) as Created_date,`Created_By`,`ok_sqty`,`ng_sqty_total`,`ProcessName` 
FROM vw_oreadyreply_list
WHERE BOM='$BOM' and ProcessNo='$ProcessNo' and MakerId='$MakerId' and sqty='$sqty'
ORDER BY Created_date DESC");	


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>已報工未移轉(產品為基準)</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <!-- Datatables -->
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css" rel="stylesheet">
        <!-- 過長表格變+號 -->
        <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
        <link href="../../resource/css/responsive.bootstrap.css" rel="stylesheet">
    
</head>

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
                                <?php
                                if(!empty($BOM)){?>

                                    <div class="x_title">
                                        <h2>報工明細</h2>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">
                                        
                                        <form action="" class="form-label-left" novalidate>
                                            <div class="item form-group">
                                                <div class="col-md-2 col-sm-6 col-xs-12">
                                                    <p><?= $BOM ?></p>
                                                </div>
                                                <div class="col-md-2 col-sm-6 col-xs-12">
                                                    <p>料號：<?= $D_Setting_Id ?></p>
                                                </div>
                                                <div class="col-md-2 col-sm-6 col-xs-12">
                                                    <p>客戶：<?= $Client_Name?></p>
                                                </div>
                                            </div>
                                        </form>
                                        <form action="" class="form-label-left" novalidate>
                                            <div class="item form-group">
                                                <div class="col-md-2 col-sm-6 col-xs-12">
                                                    <p>製程：<?=$ProcessNo?> <?=$pn?>&ensp;&ensp;<?= $MakerId ?></p>
                                                </div>
                                                <div class="col-md-2 col-sm-6 col-xs-12">
                                                    <p>發單：<?= $sqty?></p>
                                                </div>
                                            </div>
                                        </form>
                                        <p class="text-muted font-13 m-b-30">
                                        </p>
                                            <table class="table table-striped ">
                                                <thead>
                                                    <tr>
                                                        <th>報工日</th>
                                                        <th>加工數</th>
                                                        <th>良品數</th>
                                                        <th>NG數</th>
                                                        <th>人員</th>
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php
                                                    foreach ($PmOreadyReply_list as $PmOreadyReply_list){
                                                    ?>
                                                    <tr>
                                                        <td> <?= $PmOreadyReply_list['Created_date'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['oready_sqty'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['ok_sqty'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['ng_sqty_total'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['Created_By'] ?></td>
                                                    </tr>
                                                    <?php
                                                        }
                                                    ?>
                                                </tbody>
                                            </table>
                                    </div>
                                <?php } ?>
                                <?php
                                if(isset($_888)){?>

                                    <div class="x_title">
                                        <h2>已報工未移轉 <small>直接新增或點選[更新]後送出修改</small></h2>
                                        <div class="clearfix"></div>
                                    </div>
                                    
                                    <div class="x_content">
                                        <p class="text-muted font-13 m-b-30">
                                        </p>
                                        <form action="../../src/store/_NewReply.php?&d_id=<?= $d_id ?>" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                            <input id="d_id" name="d_id" value="<?= $d_id ?>" type="hidden">
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="BOM"> BOM <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="BOM" class="BOM" value="<?= $BOM ?>" data-validate-length-range="2" name="BOM"
                                                    data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="D_Setting_Id"> 產編 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="D_Setting_Id" class="D_Setting_Id" value="<?= $D_Setting_Id ?>" name="D_Setting_Id"
                                                data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ProcessNo"> 製程 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="ProcessNo" name="ProcessNo" size="5" value="<?= $ProcessNo ?>"  name="ProcessNo"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="sqty"> 發單數 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="sqty" class="sqty" value="<?= $sqty ?>" required name="sqty" 
                                                    required="required" type="text" size="5">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="oready_sqty"> 本次加工數 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="oready_sqty" class="oready_sqty" value="<?= $oready_sqty ?>" required name="oready_sqty" 
                                                    required="required" type="text" size="5"><small>(空白=發單總數)</small>
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name"> 客戶 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="Client_Name" class="Client_Name" value="<?= $Client_Name ?>" name="Client_Name"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                    
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="NG"> NG數(1) <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="NG" class="NG" value="<?= $NG ?>" size="5" data-validate-length-range="2" name="NG"
                                                    data-validate-words="1" required="required" type="text">
                                                    原因
                                                    <select name="NG_id" id="NG_id">
                                                        <option value="0">請選擇</option>
                                                        <?php foreach($ng_txt_list as $ng_txt_list){ ?>
                                                            <option value="<?= $ng_txt_list['ng_id']?>"><?= $ng_txt_list['ng_txt'] ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="NG2"> NG數(2) <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="NG2" class="NG2" value="<?= $NG2 ?>" size="5" name="NG2"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                    原因
                                                    <select name="NG_id2" id="NG_id2">
                                                        <option value="0">請選擇</option>
                                                        <?php foreach($ng_txt_list2 as $ng_txt_list2){ ?>
                                                            <option value="<?= $ng_txt_list2['ng_id']?>"><?= $ng_txt_list2['ng_txt'] ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="NG3"> NG數(3) <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="NG3" class="NG3" value="<?= $NG ?>" size="5" name="NG3"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                    原因
                                                    <select name="NG_id3" id="NG_id3">
                                                        <option value="0">請選擇</option>
                                                        <?php foreach($ng_txt_list3 as $ng_txt_list3){ ?>
                                                            <option value="<?= $ng_txt_list3['ng_id']?>"><?= $ng_txt_list3['ng_txt'] ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="id"> 報工人員 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input readonly id="id" class="id" value="<?= $id ?>" size="5"
                                                    data-validate-length-range="2" data-validate-words="1" name="id" required="required" type="text">
                                                </div>
                                            </div>

                                            <div class="ln_solid"></div>
                                            <div class="form-group">
                                                <div class="col-md-6 col-md-offset-3">
                                                    <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                    <button id="send" name="reply_UpDate" type="submit" class="btn btn-success">送出</button>
                                                </div>
                                            </div>
                                        </form>
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
                                    <h2>已報工未移轉 </h2>
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
                                            <th>BOM</th>
                                            <th>料號</th>
                                            <th>客戶</th>
                                            <th>製程</th>
                                            <th>廠商</th>
                                            <th>發單數</th>
                                            <th>已加工</th>
                                            <th>NG</th>
                                            <th>報工起始</th>
                                            <th>最後報工</th>
                                            <th></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        foreach ($OreadyReply_list as $OreadyReply_list){
                                        ?>
                                        <tr>
                                            <td name="BOM"> <?= $OreadyReply_list['BOM'] ?></td>
                                            <td name="D_Setting_Id"> <?= $OreadyReply_list['D_Setting_Id'] ?></td>
                                            <td name="Client_Name"> <?= $OreadyReply_list['Client_Name'] ?></td>
                                            <td name="ProcessName"> <?= $OreadyReply_list['ProcessNo'] ?> <?= $OreadyReply_list['ProcessName'] ?></td>
                                            <td name="MakerId"> <?= $OreadyReply_list['MakerId'] ?></td>
                                            <td name="sqty"> <?= $OreadyReply_list['sqty'] ?></td>
                                            <td name="oready_sqty_total"> <?= $OreadyReply_list['oready_sqty_total'] ?></td>
                                            <td name="ng_sqty_total"> <?= $OreadyReply_list['ng_sqty_total'] ?></td>
                                            <td name="Created_At_start"> <?= $OreadyReply_list['Created_At_start'] ?></td> <!-- This column is already "報工起始" -->
                                            <td name="Created_At_end"> <?= $OreadyReply_list['Created_At_end'] ?></td>
                                            <td>
                                                <a href="../../src/store/_pmOreadyReply_list.php?c=<?= $OreadyReply_list['Client_Name'] ?>&dsi=<?= $OreadyReply_list['D_Setting_Id'] ?>&b=<?= $OreadyReply_list['BOM'] ?>&d=<?= $OreadyReply_list['d_id'] ?>&pn=<?= $OreadyReply_list['ProcessNo'] ?>&mi=<?= $OreadyReply_list['MakerId'] ?>&s=<?= $OreadyReply_list['sqty'] ?>"><input type="button" name="oreadyReply_list" class="btn btn-warning btn-xs update" value="報工明細"></a>
                                                &ensp;&ensp;&ensp;&ensp;&ensp;&ensp;
                                                <a href="../../src/store/_pmGotoNext.php?c=<?= $OreadyReply_list['Client_Name'] ?>&dsi=<?= $OreadyReply_list['D_Setting_Id'] ?>&b=<?= $OreadyReply_list['BOM'] ?>&d=<?= $OreadyReply_list['d_id'] ?>&pn=<?= $OreadyReply_list['ProcessNo'] ?>&mi=<?= $OreadyReply_list['MakerId'] ?>&s=<?= $OreadyReply_list['sqty'] ?>"><input type="button" name="gotoNext" class="btn btn-success btn-xs update" value="已移轉"></a>
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
                </form>
            </div>
        
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
