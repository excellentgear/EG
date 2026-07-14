<?php
 if (!isset($_SESSION)){
    session_start();
    }

    unset($_SESSION['d_id']);
    unset($_SESSION['Spec_No']);
    unset($_SESSION['Client_Name']);
    unset($_SESSION['sqty']);
    unset($_SESSION['bom']);
    unset($_SESSION['bom_Del']);
    unset($_SESSION['show_del']);
    
    header("location:../../views/pm/New_Bom.php");
?>