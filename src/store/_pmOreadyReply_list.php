<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 


    // $cmd = $db->prepare("SELECT date(`Created_At`) as update_date,`Created_By`,`ok_sqty`,`ng_sqty`,`ng_id`,`ng_sqty2`,`ng_id2`,`ng_sqty3`,`ng_id3` 
    // FROM `reply`
    // WHERE `BOM`='".$_GET['b']."' and d_id='".$_GET['d']."' and `ProcessNo`='".$_GET['pn']."' and `MakerId`='".$_GET['mi']."' and `sqty`='".$_GET['s']."'");
    // $cmd->execute();
    // $row = $cmd->fetch();

    // $_SESSION['BOM']         = $row['BOM'];
    // $_SESSION['D_Setting_Id']= $row['D_Setting_Id'];
    // $_SESSION['Client_Name'] = $row['Client_Name'];
    // $_SESSION['ProcessNo']   = $row['ProcessNo'];
    // $_SESSION['MakerId']     = $row['MakerId'];
    // $_SESSION['sqty']        = $row['sqty'];
    // $_SESSION['Created_By']  = $row['Created_By'];
    // $_SESSION['update_date'] = $row['update_date'];
    // $_SESSION['ok_sqty']     = $row['ok_sqty'];
    // $_SESSION['ng_sqty']     = $row['ng_sqty'];
    // $_SESSION['ng_id']       = $row['ng_id'];
    // $_SESSION['ng_sqty2']    = $row['ng_sqty2'];
    // $_SESSION['ng_id2']      = $row['ng_id2'];
    // $_SESSION['ng_sqty3']    = $row['ng_sqty3'];
    // $_SESSION['ng_id3']      = $row['ng_id3'];

    // $dbConnection = new DBConnection();
    // $result = $dbConnection->getall("SELECT ProcessName FROM `process_no` WHERE `ProcessNo`='".$_GET['pn']."'");
    // foreach ($result as $row){
    //     $admin = $row;
    
    // $_SESSION['pn'] = $admin['ProcessName'];}


    header("location:../../views/pm/OreadyReply_ForPm.php?dsi=".$_GET['dsi']."&BOM=".$_GET['b']."&d_id=".$_GET['d']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&c=".$_GET['c']."");
?>