<?php
 if (!isset($_SESSION)){
    session_start();
    }

    unset($_SESSION['QC_Id']);
    unset($_SESSION['d_id']);
    unset($_SESSION['D_Setting_Id']);
    unset($_SESSION['Drawing_No']);
    unset($_SESSION['QC_V']);
    unset($_SESSION['QC_Skip']);
    unset($_SESSION['QC_TOTAL']);
    unset($_SESSION['ProcessNo']);
    unset($_SESSION['QC_Type']);
    unset($_SESSION['QC_List_No']);
    unset($_SESSION['QC_Scope_id']);
    unset($_SESSION['QC_Tool']);
    unset($_SESSION['QC_Scope']);
    unset($_SESSION['QC_S_Scope']);
    unset($_SESSION['Upper_Limit']);
    unset($_SESSION['Lower_Limit']);
    unset($_SESSION['opne_qc_setting_right']);


    header("Location:../../views/QC/QC_Setting.php");
?>