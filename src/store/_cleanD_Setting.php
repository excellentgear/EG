<?php
 if (!isset($_SESSION)){
    session_start();
    }

    unset($_SESSION['d_setting_Del']);
    unset($_SESSION['d_id']);
    unset($_SESSION['D_Setting_Id']);
    unset($_SESSION['Drawing_No']);
    unset($_SESSION['Spec_No']);
    unset($_SESSION['Client_Name']);

    header("location:../../views/pm/D_Setting.php");
?>