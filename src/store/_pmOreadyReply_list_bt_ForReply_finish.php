<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 
include_once '../common/DBConnection.php';
    
    $cmd = $db->prepare("SELECT `bom_ing_id`,SUM(`oready_sqty`) AS last_sqty FROM `reply` 
    WHERE `bom_ing_id`='$_GET[bi]'");
    $cmd->execute();
    $row = $cmd->fetch();
    $last_sqty=$row['last_sqty'];
    if(empty($last_sqty)){
        $last_sqty=0;
    } else {
        $last_sqty=$row['last_sqty'];
    };

    $last_sqty=$_GET['s']-$last_sqty;
    $sth = $db->prepare("INSERT INTO `reply`(completed,bom_ing_id,`BOM`,`ps`,`ProcessNo`,`sqty`,
    `oready_sqty`,`ok_sqty`,`ng_sqty`,`ng_id`,`ng_sqty2`,`ng_id2`,`ng_sqty3`,`ng_id3`,`MakerId`,
    `Created_By`,`Created_At`)
    VALUES(1,'$_GET[bi]','$_GET[b]',null,'$_GET[pn]','$_GET[s]',$last_sqty,$last_sqty,0,null,
    0,null,0,null,'$_GET[mi]','$_SESSION[id]',now())");   
    $sth->execute();

    $cmd = $db->prepare("UPDATE `bom_ing` SET `processing_state`='Q',`return_date`=NOW(),
    `Modified_By`='$_SESSION[id]',`Modified_At`=NOW() WHERE `bom_ing_id`='$_GET[bi]'");   
    $cmd->execute();


    $sth = $db->prepare("UPDATE `bom` SET `state`='Q',`Modified_By`='$_SESSION[id]',
    `Modified_At`=NOW() WHERE `bom`='$_GET[b]'"); 
    $sth->execute();

      
        header("Location:../../views/reply/reply_other.php?message=updatesuccess&c_pti=".$_GET['c_pti']."&bi=".$_GET['bi']."&BOM=".$_GET['b']."&d=".$_GET['d']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&C=".$_SESSION['C']."");
        // header("Location:../../views/reply/reply_other.php?ttt=".$last_sqty);
        ?>