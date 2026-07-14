<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../common/_config.php");
include_once '../common/DBConnection.php';



// 清除
if (isset($_POST["resetpSetting"])) {

    
    unset($_SESSION['Upper_Limit']);
    unset($_SESSION['Lower_Limit']);
    unset($_SESSION['QC_S_Scope']);
    unset($_SESSION['QC_Scope']);

    header("Location:../../views/QC/QC_Setting.php");
}