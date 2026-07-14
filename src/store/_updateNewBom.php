<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

    unset($_SESSION['d_id']);
    unset($_SESSION['Spec_No']);
    unset($_SESSION['Client_Name']);
    unset($_SESSION['sqty']);
    unset($_SESSION['bom']);
    unset($_SESSION['bom_Del']);
    unset($_SESSION['show_del']);


    $cmd = $db->prepare("SELECT * FROM bom WHERE bom='".$_GET['b']."'");
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['bom']         = $row['bom'];
    $_SESSION['d_id']        = $row['d_id'];
    $_SESSION['Spec_No']     = $row['Spec_No'];     //規格
    $_SESSION['Client_Name'] = $row['Client_Name']; //客戶名稱
    $_SESSION['sqty']        = $row['sqty'];        //數量

    header("location:../../views/pm/New_Bom.php");
?>