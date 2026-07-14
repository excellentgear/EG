<?php
session_start();

if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage']="../../views/reply/reply_other.php?BOM=".$_GET['BOM']."&d_id=".$_GET['d_id']."&ProcessNo=".$_GET['ProcessNo']."&sqty=".$_GET['sqty']."&D=".$_GET['D']."&C=".$_GET['C']."&m=".$_GET['m'];
    header("Location:../../index.php?BOM=".$_GET['BOM']."&d_id=".$_GET['d_id']."&ProcessNo=".$_GET['ProcessNo']."&sqty=".$_GET['sqty']."&D=".$_GET['D']."&C=".$_GET['C']."&m=".$_GET['m']); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';


@$userName      = $_SESSION['user_cname'];
@$id            = $_SESSION['id'];

@$BOM           = $_GET['BOM'];
@$ProcessNo     = $_GET['ProcessNo'];
@$sqty          = $_GET['sqty'];
@$Client_Name   = $_GET['C'];
@$MakerId       = $_GET['MakerId'];

@$bom           = $_GET['bom'];     
@$outsource_date= $_GET['outsource_date'];
@$ProcessName   = $_GET['pna'];  
@$bom_ing_id    = $_GET['bi'];
@$d_id          = $_GET['d'];
@$replydate     = $_GET['rd']; // 更新日期

// 更新報工紀錄
@$oready_sqty = $_SESSION['oready_sqty'];
@$ok_sqty     = $_SESSION['ok_sqty'];    
@$ng_sqty     = $_SESSION['ng_sqty'];   
@$ng_id       = $_SESSION['ng_id'];      
@$ng_sqty2    = $_SESSION['ng_sqty2'];   
@$ng_id2      = $_SESSION['ng_id2'];     
@$ng_sqty3    = $_SESSION['ng_sqty3'];   
@$ng_id3      = $_SESSION['ng_id3'];     
@$completed   = $_SESSION['completed'];  
@$Created_By  = $_SESSION['Created_By']; 
@$Created_At  = $_SESSION['Created_At']; 


//料號
@$conn = new DBConnection();
//上方左欄使用
// @$ALL = $conn->getAll("SELECT * FROM `QC` ORDER BY QC_Id");

@$conn = new DBConnection();
@$ng_txt_list = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");
@$ng_txt_list2 = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");
@$ng_txt_list3 = $conn->getAll("SELECT ng_id,ng_txt FROM `ng_txt` ORDER BY ng_txt");

// 機台
@$pti=$_GET['pti'];
@$machine_id_list = $conn->getAll("SELECT * 
FROM machine_list 
WHERE machine_type_id = '$pti' 
AND RIGHT(machine_id, 1) != '0' ORDER BY `machine_id`");



@$last_sqty=$_SESSION['last_sqty'];

// 待加工清單
@$ALL_Sce = $conn->getAll("SELECT bom_ing.bom_ing_id,bom.bom,bom.d_id,DATE_FORMAT(bom_ing.outsource_date,'%m/%d')AS outsource_date,process_no.process_type_id,bom_ing.processing_state,
process_no.ProcessNo, process_no.ProcessName,bom_ing.maker_id,bom_ing.sqty,bom.Client_Name 
FROM bom_ing
LEFT JOIN process_no ON process_no.ProcessNo=bom_ing.process_no
LEFT JOIN bom ON Bom.bom=bom_ing.bom
WHERE bom.state='ing' and bom_ing.maker_id IS NOT null and bom_ing.return_date is null
ORDER BY bom_ing.outsource_date");

@$OreadyReply_list = $conn->getAll("SELECT `BOM`,`Client_Name`,`sqty`,`oready_sqty`,`ProcessName`,`ProcessNo`,`MakerId`,
date(`Created_At`) as Created_At_s,`ok_sqty`,`ng_sqty_total`,`ps` FROM vw_oreadyreply_list ORDER BY Created_At DESC");


@$width = $_GET['width'];
@$m     = $_GET['m'];
@$t     = $_GET['t'];

@$new_data=$_GET['new_data'];

// 已報工紀錄
if(isset($bom_ing_id)){
@$PmOreadyReply_list = $conn->getAll("SELECT vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width,
vw_oreadyreply_list.reply_id,vw_oreadyreply_list.BOM,vw_oreadyreply_list.oready_sqty,
date(vw_oreadyreply_list.Created_At) as Created_date,vw_oreadyreply_list.Created_At as Created_date_ORDER,vw_oreadyreply_list.Created_By,
vw_oreadyreply_list.ok_sqty,vw_oreadyreply_list.ng_sqty_total,user.user_cname,vw_oreadyreply_list.ps,
vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width,vw_oreadyreply_list.mc_id,vw_oreadyreply_list.mc_time,
vw_oreadyreply_list.processing_time,machine_list.machine,vw_oreadyreply_list.mc_user,
vw_oreadyreply_list.sqty,BOM.d_id,vw_oreadyreply_list.bom_ing_id,
vw_oreadyreply_list.oready_sqty,vw_oreadyreply_list.ProcessName,vw_oreadyreply_list.ProcessNo,vw_oreadyreply_list.MakerId
FROM vw_oreadyreply_list
LEFT JOIN user ON user.id=vw_oreadyreply_list.Created_By
LEFT JOIN machine_list ON vw_oreadyreply_list.machine_id=machine_list.machine_id
LEFT JOIN BOM ON BOM.bom=vw_oreadyreply_list.BOM
WHERE vw_oreadyreply_list.bom_ing_id='$bom_ing_id'
ORDER BY Created_date_ORDER DESC;");

};



@$reply_id=$_GET['ri'];


@$ri = $conn->getAll("SELECT vw_oreadyreply_list.reply_id,vw_oreadyreply_list.BOM,vw_oreadyreply_list.oready_sqty,
                        date(vw_oreadyreply_list.Created_At) as Created_date,vw_oreadyreply_list.Created_At as Created_date_ORDER,vw_oreadyreply_list.Created_By,
                        vw_oreadyreply_list.ok_sqty,vw_oreadyreply_list.ng_sqty_total,user.user_cname,vw_oreadyreply_list.ps,
                        vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width,vw_oreadyreply_list.mc_id,vw_oreadyreply_list.mc_time,
                        vw_oreadyreply_list.processing_time,machine_list.machine,vw_oreadyreply_list.mc_user,vw_oreadyreply_list.sqty,                     vw_oreadyreply_list.oready_sqty,vw_oreadyreply_list.ProcessName,vw_oreadyreply_list.ProcessNo,vw_oreadyreply_list.MakerId,
                        reply.ng_sqty,reply.ng_id,reply.ng_sqty2,reply.ng_id2,reply.ng_sqty3,reply.ng_id3
                        FROM vw_oreadyreply_list
                        LEFT JOIN user ON user.id=vw_oreadyreply_list.Created_By
                        LEFT JOIN machine_list ON vw_oreadyreply_list.machine_id=machine_list.machine_id
                        LEFT JOIN reply ON reply.reply_id=vw_oreadyreply_list.reply_id
                        WHERE vw_oreadyreply_list.reply_id='$reply_id'
                        ORDER BY Created_date_ORDER DESC");

        if($reply_id!=""){
        foreach($ri as $ri){
        @$oready_sqty = $ri['oready_sqty'];
        @$ok_sqty     = $ri['ok_sqty'];    
        @$NG          = $ri['ng_sqty'];   
        @$ng_txt_id   = $ri['ng_id'];      
        @$NG2         = $ri['ng_sqty2'];   
        @$ng_txt_id2  = $ri['ng_id2'];     
        @$NG3         = $ri['ng_sqty3'];   
        @$ng_txt_id3  = $ri['ng_id3'];
        @$ps          = $ri['ps'];          
        @$completed   = $ri['completed'];  
        @$Created_By  = $ri['Created_By']; 
        @$Created_At  = $ri['Created_At']; 
}
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

    <title>報工</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">

    <!-- Bootstrap
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    Font Awesome -->
    <!-- <link href="../../resource/css/font-awesome.css" rel="stylesheet"> -->
    <!-- NProgress -->
    <!-- <link href="../../resource/css/nprogress.css" rel="stylesheet"> -->
    <!-- iCheck -->
    <!-- <link href="../../resource/css/green.css" rel="stylesheet"> -->
    <!-- Datatables -->
    <!-- <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/responsive.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css" rel="stylesheet"> -->
    <!-- Custom Theme Style -->
    <!-- <link href="../../resource/css/custom.css" rel="stylesheet"> -->
    <!-- <link href="../../resource/css/pages.css" rel="stylesheet"> -->

    <!-- 日期選單用 -->
    <link rel="stylesheet" href="http://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css">
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
                                    報工成功
                                    </div>";
                                } else if($_GET['message']=="updatesuccess"){
                                    echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    更新成功
                                    </div>";
                                } else if($_GET['message']=="del"){
                                    echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    已刪除紀錄
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
                                    <h2>報工 <small>直接新增或點選[更新]後送出修改</small></h2>
                                    <div class="clearfix"></div>
                                </div>
    
                                <div class="x_content">
                                    <p class="text-muted font-13 m-b-30">
                                    </p>
                                    <form action="../../src/store/_NewReply.php?pti=<?= $pti ?>&BOM=<?= $bom_ing_id ?>" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                        <input id="reply_id" name="reply_id" value="<?= $reply_id ?>" type="hidden">
                                        <input id="bom_ing_id" name="bom_ing_id" value="<?= $bom_ing_id ?>" type="hidden">
                                        
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="datepicker">報工日期 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <div class="datetest">
                                                    <input type="text" id="datepicker" value="<?= $replydate ?>" name='replydate' size="8">
                                                    <small>(預設為今日)</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="TEST"> TEST <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="TEST" class="TEST" value="INSERT INTO `reply` (`reply_id`, `BOM`, `bom_ing_id`, `ps`, `Client_Name`, `sqty`, `oready_sqty`, `ProcessNo`, `MakerId`, `ok_sqty`, `ng_sqty`, `ng_id`, `ng_sqty2`, `ng_id2`, `ng_sqty3`, `ng_id3`, `completed`, `Created_By`, `Created_At`, `Modified_By`, `Modified_At`) VALUES (NULL, 'B-1110101001', 'TEST', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);" name="TEST"
                                                data-validate-length-range="2" data-validate-words="1" required="required" type="text" size="5">
                                            </div>
                                        </div>
                                        <?php
                                        if($Client_Name==null){
                                        ?>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name"> 客戶 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="Client_Name" class="Client_Name" value="<?= $Client_Name ?>" name="Client_Name"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text" size="5">
                                                </div>
                                            </div>
                                        <?php } else { ?>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name"> 客戶 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="Client_Name" class="Client_Name" value="<?= $Client_Name ?>" name="Client_Name" size="5"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text" readonly style="border-style:none">
                                                </div>
                                            </div>
                                        <?php } ?>
                                        <?php
                                        if($BOM==null){
                                        ?>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="BOM"> 製令單號(BOM) <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="BOM" class="BOM" value="<?= $BOM ?>" data-validate-length-range="2" name="BOM"
                                                data-validate-words="1" required="required" type="text">
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="d_id"> 料號 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="d_id" class="d_id" value="<?= $d_id ?>" data-validate-length-range="2" name="d_id"
                                                data-validate-words="1" required="required" type="text">
                                            </div>
                                        </div>
                                        <?php } else { ?>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="BOM"> 製令單號(BOM) <span class="required">*</span>
                                                </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="BOM" class="BOM" value="<?= $BOM ?>" data-validate-length-range="2" name="BOM"
                                                data-validate-words="1" required="required" type="text" readonly style="border-style:none">
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="d_id"> 料號 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="d_id" class="d_id" value="<?= $d_id ?>" data-validate-length-range="2" name="d_id"
                                                data-validate-words="1" required="required" type="text" readonly style="border-style:none">
                                            </div>
                                        </div>
                                            <?php if($ProcessNo==12){ ?>
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="MTW"> M / T / W <span class="required">*</span>
                                                    </label>
                                                    <div class="col-md-6 col-sm-6 col-xs-12">
                                                        <input id="m" class="m" required name="m" value="<?= $m ?>" required="required" type="text" size="5">
                                                        <input id="t" class="t" required name="t" value="<?= $t ?>" required="required" type="text" size="5">
                                                        <input id="w" class="w" required name="width" value="<?= $width ?>" required="required" type="text" size="5">
                                                    </div>
                                                </div>
                                            <?php } ?>
                                        <?php } ?>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ProcessNo"> 製程 <span class="required">*</span>
                                            </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                <?php
                                                if($ProcessNo==null){ ?>
                                                    <input id="ProcessNo" name="ProcessNo" size="5" value="<?= $ProcessNo ?>"  name="ProcessNo"
                                                        data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                    <input id="ProcessName" name="ProcessName" size="5" value="<?= $ProcessName ?>"  name="ProcessName"
                                                        data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                    <input id="MakerId" name="MakerId" size="5" value="<?= $MakerId ?>"  name="MakerId"
                                                        data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                <?php } else { ?>
                                                    <input id="ProcessNo" name="ProcessNo" value="[<?= $ProcessNo ?>] <?= $ProcessName ?> - <?= $MakerId ?>"  name="ProcessNo"
                                                        data-validate-length-range="2" data-validate-words="1" required="required" type="text" readonly  style="border-style:none">
                                                    <input id="ProcessNo" name="ProcessNo" size="5" value="<?= $ProcessNo ?>"  name="ProcessNo"
                                                        data-validate-length-range="2" data-validate-words="1" required="required" type="hidden">
                                                    <input id="ProcessName" name="ProcessName" size="5" value="<?= $ProcessName ?>"  name="ProcessName"
                                                        data-validate-length-range="2" data-validate-words="1" required="required" type="hidden">
                                                    <input id="MakerId" name="MakerId" size="5" value="<?= $MakerId ?>"  name="MakerId"
                                                        data-validate-length-range="2" data-validate-words="1" required="required" type="hidden">
                                                <?php } ?>
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
                                                    required="required" type="text" size="5">
                                                    <small>(空白=發單總數)</small>
                                                </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="machine_id"> 機台 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <select name="machine_id" id="machine_id">
                                                    <option value="0">請選擇</option>
                                                    <?php foreach($machine_id_list as $machine_id_list){ ?>
                                                        <option value="<?= $machine_id_list['machine_id']?>"><?= $machine_id_list['machine'] ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>

                                        
                                        
                                        <!-- 以下齒研加工才出現 -->
                                        <?php if($ProcessNo==12){ ?>
                                            <!-- <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="m"> 模數 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="m" class="m" value="<?= $m ?>" name="m" size="5"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="t"> 齒數 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="t" class="t" value="<?= $t ?>" name="t" size="5"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="width"> W <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="width" class="width" value="<?= $width ?>" name="width" size="5"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div> -->
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="mc_id"> 校機人員 
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="mc_id" class="mc_id" value="<?= $mc_id ?>" name="mc_id" size="5"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="mc_time"> 校機時間 
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="mc_time" class="mc_time" value="<?= $mc_time ?>" name="mc_time" size="6"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="processing_time"> 加工時間
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="processing_time" class="processing_time" value="<?= $processing_time ?>" name="processing_time" size="6"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                        <?php } ?>
                                        <!-- 齒研加工-end -->

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
                                                        <option value="<?= $ng_txt_list['ng_id']?>" <?php if($ng_txt_id==$ng_txt_list['ng_id']){ echo "selected"; }?>>
                                                            <?= $ng_txt_list['ng_txt'] ?></option>
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
                                                        <option value="<?= $ng_txt_list2['ng_id']?>" <?php if($ng_txt_id2==$ng_txt_list2['ng_id']){ echo "selected"; }?>>
                                                            <?= $ng_txt_list2['ng_txt'] ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="NG3"> NG數(3) <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="NG3" class="NG3" value="<?= $NG3 ?>" size="5" name="NG3"
                                                data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                原因
                                                <select name="NG_id3" id="NG_id3">
                                                    <option value="0">請選擇</option>
                                                    <?php foreach($ng_txt_list3 as $ng_txt_list3){ ?>
                                                        <option value="<?= $ng_txt_list3['ng_id']?>" <?php if($ng_txt_id3==$ng_txt_list3['ng_id']){ echo "selected"; }?>>
                                                            <?= $ng_txt_list3['ng_txt'] ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ps"> 備註 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="ps" class="ps" value="<?= $ps ?>" name="ps" size="5"
                                                data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                            </div>
                                        </div>
                                        <div class="item form-group">
                                            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="id"> 報工人員 <span class="required">*</span>
                                            </label>
                                            <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input readonly id="id" class="id" value="<?= $id ?>" size="5"
                                                data-validate-length-range="2" data-validate-words="1" name="id" required="required" type="text" readonly  style="border-style:none">
                                            </div>
                                        </div>

                                        <div class="ln_solid"></div>
                                        <div class="form-group">
                                            <div class="col-md-6 col-md-offset-3">
                                                <?php if($reply_id!=""){ ?>
                                                    <a href="../../src/store/_deleteReply.php?pti=<?= $pti ?>&ri=<?= $reply_id ?>&bi=<?= $bom_ing_id ?>&BOM=<?= $BOM ?>&d=<?= $d_id ?>&pna=<?= $ProcessName ?>&pn=<?= $ProcessNo ?>&mi=<?= $MakerId ?>&s=<?= $sqty ?>&C=<?= $Client_Name ?>"> 
                                                        <input type="button" name="reply_other_del" class="btn btn-danger" value="刪除"></a>&ensp;
                                                    </a>
                                                    &emsp;
                                                    <button name="reply_other_resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                    <button id="update" name="reply_other_update" type="submit" class="btn btn-warning update">更新</button>
                                                <?php } else { ?>
                                                    <button name="reply_other_resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                    <button id="send" name="reply_other_New" type="submit" class="btn btn-success">送出</button>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </form>

                                    <?php
                                    if($BOM!=null){
                                    ?>
                                    <!-- 已報工紀錄 -->
                                    
                                    <table class="table table-striped ">
                                        <thead>
                                            <tr>
                                                <th>報工日</th>
                                                <th>加工數</th>
                                                <th>良品數</th>
                                                <th>NG數</th>
                                                <th>人員</th>
                                                <th>備註</th>
                                                <!-- 以下齒研加工才出現 -->
                                                <?php if($ProcessNo==12){ ?>
                                                    <th>其他資訊</th> 
                                                <?php } ?>
                                                <!-- 齒研加工才出現-end -->
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <!-- 以下齒研加工才出現 -->
                                            <?php
                                            if($ProcessNo==12){
                                                foreach ($PmOreadyReply_list as $PmOreadyReply_list){
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="../../src/store/_showReply.php?pti=<?= $pti ?>&ri=<?= $PmOreadyReply_list['reply_id'] ?>&BOM=<?= $PmOreadyReply_list['BOM']?>&bi=<?= $PmOreadyReply_list['bom_ing_id']?>&d=<?= $PmOreadyReply_list['d_id']?>&pna=<?= $PmOreadyReply_list['ProcessName'] ?>&pn=<?= $PmOreadyReply_list['ProcessNo'] ?>&mi=<?= $PmOreadyReply_list['MakerId'] ?>&s=<?= $sqty ?>&C=<?= $Client_Name ?>&rd=<?= $PmOreadyReply_list['Created_date'] ?>"> 
                                                        <input type="button" name="reply_other_UD" class="btn btn-warning btn-xs update" value="更新"></a>&ensp;
                                                    </a>
                                                    <?= $PmOreadyReply_list['Created_date'] ?></td>
                                                <td> <?= $PmOreadyReply_list['oready_sqty'] ?></td>
                                                <td> <?= $PmOreadyReply_list['ok_sqty'] ?></td>
                                                <td> <?= $PmOreadyReply_list['ng_sqty_total'] ?></td>
                                                <td> <?= $PmOreadyReply_list['user_cname'] ?></td>
                                                <td> <?= $PmOreadyReply_list['ps'] ?></td>
                                                <th> <?php if($PmOreadyReply_list['m']!='NULL'){ ?>
                                                        <!-- 模數 <?= $PmOreadyReply_list['m'] ?> T<?= $PmOreadyReply_list['t'] ?> W<?= $PmOreadyReply_list['width'] ?>  -->
                                                        <?php if($PmOreadyReply_list['machine']!='NULL'){ ?>
                                                            <?= $PmOreadyReply_list['machine'] ?>
                                                        <? }; if($PmOreadyReply_list['mc_time']!='NULL'){  ?><BR>
                                                        校機 <?= $PmOreadyReply_list['mc_time'] ?> / <?= $PmOreadyReply_list['mc_user'] ?><BR>
                                                        <?php }; if($PmOreadyReply_list['processing_time']!='NULL'){ ?>
                                                        加工時間 <?= $PmOreadyReply_list['processing_time'] ?>
                                                        <? } ?>
                                                <? } ?>
                                                </th>
                                            </tr>
                                            <!-- 齒研加工才出現-end -->
                                            <?php }} else {
                                                foreach ($PmOreadyReply_list as $PmOreadyReply_list){
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="../../src/store/_showReply.php?ri=<?= $PmOreadyReply_list['reply_id'] ?>&BOM=<?= $PmOreadyReply_list['BOM']?>&bi=<?= $PmOreadyReply_list['bom_ing_id']?>&d=<?= $PmOreadyReply_list['d_id']?>&pna=<?= $PmOreadyReply_list['ProcessName'] ?>&pn=<?= $PmOreadyReply_list['ProcessNo'] ?>&mi=<?= $PmOreadyReply_list['MakerId'] ?>&s=<?= $sqty ?>&C=<?= $Client_Name ?>&rd=<?= $PmOreadyReply_list['Created_date'] ?>">
                                                        <input type="button" name="reply_other_UD" class="btn btn-warning btn-xs update" value="更新"></a>&ensp;
                                                    </a>
                                                    <?= $PmOreadyReply_list['Created_date'] ?></td>
                                                <td> <?= $PmOreadyReply_list['oready_sqty'] ?></td>
                                                <td> <?= $PmOreadyReply_list['ok_sqty'] ?></td>
                                                <td> <?= $PmOreadyReply_list['ng_sqty_total'] ?></td>
                                                <td> <?= $PmOreadyReply_list['user_cname'] ?></td>
                                                <td> <?= $PmOreadyReply_list['ps'] ?></td>
                                            </tr>
                                            <?php }} ?>
                                        </tbody>
                                    </table>
                                    <?php } ?>
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
                                <h2>發單總覽 
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
                                
                                <table id="datatable-buttons" class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th hidden="hidden">id</th>
                                            <th>BOM</th>
                                            <th>料號</th>
                                            <th>發單日</th>
                                            <th>製程</th>
                                            <th>廠商</th>
                                            <th>總數</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($ALL_Sce as $ALL_Sce) {
                                            ?>
                                            <tr>
                                                <!-- hidden="hidden" -->
                                                <td hidden="hidden" name="bom_ing_id">
                                                    <?= $ALL_Sce['bom_ing_id'] ?>
                                                </td>
                                                <td name="BOM">
                                                    <a href="../../src/store/_pmOreadyReply_list_bt_ForReply.php?pti=<?= $ALL_Sce['process_type_id'] ?>&bi=<?= $ALL_Sce['bom_ing_id'] ?>&d=<?= $ALL_Sce['d_id'] ?>&b=<?= $ALL_Sce['bom'] ?>&pna=<?= $ALL_Sce['ProcessName'] ?>&pn=<?= $ALL_Sce['ProcessNo'] ?>&mi=<?= $ALL_Sce['maker_id'] ?>&s=<?= $ALL_Sce['sqty'] ?>">
                                                        <input type="button" name="oreadyReply_list" class="btn btn-primary btn-xs update" value="報工"></a>&ensp;
                                                    </a>
                                                    <?php if($ALL_Sce['process_type_id']!="12"){ ?>
                                                        <a href="../../src/store/_pmOreadyReply_list_bt_ForReply_finish.php?bi=<?= $ALL_Sce['bom_ing_id'] ?>&d=<?= $ALL_Sce['d_id'] ?>&b=<?= $ALL_Sce['bom'] ?>&pn=<?= $ALL_Sce['ProcessNo'] ?>&mi=<?= $ALL_Sce['maker_id'] ?>&s=<?= $ALL_Sce['sqty'] ?>">
                                                            <input type="button" name="oreadyReply_list" class="btn btn-warning btn-xs update" value="完工"></a>&ensp;
                                                        </a>
                                                    <?php } ?>
                                                    <?= $ALL_Sce['bom'] ?>
                                                </td>
                                                <td name="d_id">
                                                    <!-- href="../pm/bom/<?= $ALL_Sce['bom'] ?>.jpg" -->
                                                    <?= $ALL_Sce['d_id'] ?>
                                                </td>
                                                <td name="outsource_date">
                                                    <?= $ALL_Sce['outsource_date'] ?>
                                                </td>
                                                <td name="pn">
                                                <?php if($ALL_Sce['ProcessNo']==""){ ?>
                                                    [未設定 BOM]
                                                <?php } else { ?>
                                                    [<?= $ALL_Sce['ProcessNo'] ?>] <?= $ALL_Sce['ProcessName'] ?>
                                                <?php } ?>
                                                </td>
                                                <td name="maker_id">
                                                    <?= $ALL_Sce['maker_id'] ?>
                                                </td>
                                                <td name="sqty">
                                                    <?= $ALL_Sce['sqty'] ?>
                                                </td>
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

<!-- 以下日期選單用 -->
    <script src="http://code.jquery.com/ui/1.11.4/jquery-ui.js"></script>
    <script>
        $(function() {
            $("#datepicker").datepicker({
                //可使用下拉式選單 - 月份
                changeMonth: true,
                //可使用下拉式選單 - 年份
                changeYear: true,
                //設定 下拉式選單月份 在 年份的後面
                showMonthAfterYear: true
            });
        });

        $(function() {
            (function(factory) {
                if (typeof define === "function" && define.amd) {
                    // AMD. Register as an anonymous module.
                    define(["../widgets/datepicker"], factory);
                } else {
                    // Browser globals
                    factory(jQuery.datepicker);
                }

            }(function(datepicker) {
                datepicker.regional["zh-TW"] = {
                    closeText: "關閉",
                    prevText: "&#x3C;上個月",
                    nextText: "下個月&#x3E;",
                    currentText: "今天",
                    monthNames: ["一月", "二月", "三月", "四月", "五月", "六月",
                        "七月", "八月", "九月", "十月", "十一月", "十二月"
                    ],
                    monthNamesShort: ["一月", "二月", "三月", "四月", "五月", "六月",
                        "七月", "八月", "九月", "十月", "十一月", "十二月"
                    ],
                    dayNames: ["星期日", "星期一", "星期二", "星期三", "星期四", "星期五", "星期六"],
                    dayNamesShort: ["週日", "週一", "週二", "週三", "週四", "週五", "週六"],
                    dayNamesMin: ["日", "一", "二", "三", "四", "五", "六"],
                    weekHeader: "週",
                    dateFormat: "yy-mm-dd",
                    firstDay: 1,
                    isRTL: false,
                    showMonthAfterYear: true,
                    yearSuffix: "年"
                };

                datepicker.setDefaults(datepicker.regional["zh-TW"]);
                return datepicker.regional["zh-TW"];

            }));
        });
    </script>
</body>
</html>
