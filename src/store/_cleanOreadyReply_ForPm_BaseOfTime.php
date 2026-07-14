<?php
 if (!isset($_SESSION)){
    session_start();
    }

    unset($_SESSION['pn']);
    // unset($_GET['BOM']);    
    // unset($_GET['ProcessNo']);
    // unset($_GET['MakerId']);
    // unset($_GET['sqty']);  
    // unset($_GET['d_id']);
    // unset($_GET['dsi']);
    // unset($_GET['c']);

    header("location:../../views/pm/OreadyReply_ForPm_BaseOfTime.php");
?>