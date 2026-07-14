<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 
include_once '../common/DBConnection.php';

    $dbConnection = new DBConnection();
    $result = $dbConnection->getall("SELECT ProcessName FROM `process_no` WHERE `ProcessNo`='".$_GET['pn']."'");
    foreach ($result as $row){
        $admin = $row;
    
    $_SESSION['pn'] = $admin['ProcessName'];}


    header("location:../../views/pm/OreadyReply_ForPm_BaseOfTime.php?c_pti=".$_GET['c_pti']."&dsi=".$_GET['dsi']."&BOM=".$_GET['b']."&d_id=".$_GET['d']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&c=".$_GET['c']."");
?>