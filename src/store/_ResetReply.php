<?php
 if (!isset($_SESSION)){
    session_start();
    }
    include("../common/_config.php");

   
        unset($_SESSION['BOM']);
        unset($_SESSION['ProcessNo']);
        unset($_SESSION['stqy']); 
        unset($_SESSION['oready_sqty']); 
        unset($_SESSION['Client_Name']); 
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
        unset($_SESSION['Created_By']); 
        unset($_SESSION['Created_At']); 
        
        header("Location:../../views/reply/reply_other.php");