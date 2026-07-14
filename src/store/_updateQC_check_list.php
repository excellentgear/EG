<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../common/_config.php");
include_once '../common/DBConnection.php';

//更新  QC報工

        // $cmd = $db->prepare("UPDATE `bom_ing` SET `Modified_By`='$_GET[id]',
        // `Modified_At`=now(),`QC_check`='QQ',QC_check_date=now() 
        // WHERE `bom_ing_id`='$_GET[bi]'");


        // $cmd->execute();


        header("Location:../../views/QC/QC_check_list.php?message=success&id=".$_GET['id']);
