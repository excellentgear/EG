<?php
 if (!isset($_SESSION)){
    session_start();
    };
    include('../../src/common/DBConnection.php');


    unset($_SESSION['NG']); 
    unset($_SESSION['NG2']); 
    unset($_SESSION['NG3']); 
    unset($_SESSION['oready_sqty']);
    unset($_SESSION['ok_sqty']);    
    unset($_SESSION['ng_sqty']);   
    unset($_SESSION['ng_id']);      
    unset($_SESSION['ng_sqty2']);   
    unset($_SESSION['ng_id2']);     
    unset($_SESSION['ng_sqty3']);   
    unset($_SESSION['ng_id3']);     
    unset($_SESSION['completed']); 


    $conn = new DBConnection();

    $sth = $conn->execute("DELETE FROM reply_all WHERE reply_id=".$_GET['ri']);

   //  IF ($_POST['ProcessNo']==12){
   //       $sth = $conn->execute("DELETE FROM reply_tg WHERE reply_id=".$_GET['ri']);
   //    };


    header("location:../../views/pm/OreadyReply_ForPm_BaseOfTime.php?message=del&pti=".$_GET['pti']."&bi=".$_GET['bi']."&BOM=".$_GET['BOM']."&d=".$_GET['d']."&pna=".$_GET['pna']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&C=".$_GET['C']."");
          