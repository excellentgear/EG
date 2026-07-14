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
    unset($_SESSION['bom']);
    unset($_SESSION['ProcessNo']);
    unset($_SESSION['sqty']);
    unset($_SESSION['QC_Id']);
    unset($_SESSION['QC_V']);
    unset($_SESSION['Spec_No']);
    unset($_SESSION['QC_List_No']);
    unset($_SESSION['QC_TOTAL']);

    header("location:../../views/QC/IPQC_Setting.php");
?>