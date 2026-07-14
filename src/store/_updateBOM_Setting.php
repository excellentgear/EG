<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

    unset($_SESSION['d_id']);
    unset($_SESSION['D_Setting_Id']);
    unset($_SESSION['Drawing_No']);
    unset($_SESSION['Spec_No']);
    unset($_SESSION['Client_Name']);
    unset($_SESSION['sqty']);
    unset($_SESSION['bom']);
    unset($_SESSION['bom_Del']);
    unset($_SESSION['show_del']);


    $cmd = $db->prepare("SELECT * FROM d_setting WHERE d_id='".$_GET['d_id']."'");
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['d_id']        = $row['d_id'];
    $_SESSION['D_Setting_Id']= $row['D_Setting_Id'];
    $_SESSION['Drawing_No']  = $row['Drawing_No']; //料號
    $_SESSION['Spec_No']     = $row['Spec_No']; //規格
    $_SESSION['Client_Name'] = $row['Client_Name']; //客戶名稱

    header("location:../../views/pm/BOM_Setting.php");
?>