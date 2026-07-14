<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

    //左側欄
    unset($_SESSION['QC_Id']);
    unset($_SESSION['d_id']);
    unset($_SESSION['QC_V']);
    unset($_SESSION['QC_Skip']);
    unset($_SESSION['QC_TOTAL']);
    unset($_SESSION['ProcessNo']);
    unset($_SESSION['QC_Type']);

    //右側欄
    unset($_SESSION['opne_qc_setting_right']);

    unset($QC_Id);
    unset($QC_V); //版本

    $d_id=$_GET['d_id'];
    $ProcessNo=$_GET['ProcessNo'];

    $cmd = $db->prepare("SELECT * FROM d_setting WHERE d_id ='$d_id'");
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['d_id']= $row['d_id'];
    $_SESSION['D_Setting_Id']= $row['D_Setting_Id'];
    $_SESSION['Drawing_No']  = $row['Drawing_No']; //料號
    $_SESSION['Spec_No']     = $row['Spec_No']; //規格
    unset($_SESSION['QC_Id']);

    
    $cmd = $db->prepare("SELECT QC_Type FROM qc WHERE d_id='$d_id' and ProcessNo='$ProcessNo' AND NOT(d_id IS NULL) ORDER BY ProcessNo");
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['QC_Type']=$row['QC_Type'];
    $_SESSION['ProcessNo']=$ProcessNo;

header("location:../../views/QC/QC_Setting.php?d_id=".$d_id."&QC_Type=".$_SESSION['QC_Type']."&ProcessNo=".$ProcessNo);
?>