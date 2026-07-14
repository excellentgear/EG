<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 
include_once '../common/DBConnection.php';

    $dbConnection = new DBConnection();
    $result = $dbConnection->getall("SELECT bom.bom,bom.Client_Name,vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width
    FROM bom 
    LEFT JOIN vw_oreadyreply_list on vw_oreadyreply_list.bom=bom.bom
    WHERE bom.bom='".$_GET['b']."'");
    foreach ($result as $row){
        $admin = $row;
    
    $_SESSION['C'] = $admin['Client_Name'];
    $_SESSION['m'] = $admin['m'];
    $_SESSION['t'] = $admin['t'];
    $_SESSION['width'] = $admin['width'];
        
}

// if(isset($_GET['new_data'])){
//     header("location:../../views/reply/reply_other.php?new_data=1&pti=".$_GET['pti']."&bi=".$_GET['bi']."&BOM=".$_GET['b']."&d=".$_GET['d']."&pna=".$_GET['pna']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&C=".$_SESSION['C']."");

// } else {
    header("location:../../views/reply/reply_other.php?pti=".$_GET['pti']."&bi=".$_GET['bi']."&BOM=".$_GET['b']."&d=".$_GET['d']."&pna=".$_GET['pna']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&C=".$_SESSION['C']."");
// }

    
?>