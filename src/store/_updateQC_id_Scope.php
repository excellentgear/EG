<?php
 if (!isset($_SESSION)){
    session_start();
    }

    //2024.03.22 ok

    include("../../src/common/_config.php"); 

    if (isset($_POST["QC_Scope_update"])) {
    
        if (isset($_POST['QC_S_Scope'])){
            $_POST['QC_S_Scope']=1;
        } else {
            $_POST['QC_S_Scope']=0;
        };

        if ($_POST['QC_Tool']=="0"){
            header("location:../../views/QC/QC_Setting.php?message=請選擇檢驗工具");
        } else {
        };


    $sth = $db->prepare("UPDATE qc_scope SET QC_List_No='$_POST[QC_List_No]',QC_Tool='$_POST[QC_Tool]',QC_Scope='$_POST[QC_Scope]',QC_S_Scope='$_POST[QC_S_Scope]',
    Upper_Limit='$_POST[Upper_Limit]',Lower_Limit='$_POST[Lower_Limit]',Modified_By='$_SESSION[id]' 
    WHERE `QC_Scope_id`=".$_POST['QC_Scope_id']);
    $sth->execute();

    $_SESSION['QC_Scope_id']=$_POST['QC_Scope_id'];
    $_SESSION['QC_List_No']=$_POST['QC_List_No'];
    $_SESSION['QC_Tool']=$_POST['QC_Tool'];
    $_SESSION['QC_Scope']=$_POST['QC_Scope'];
    $_SESSION['QC_S_Scope']=$_POST['QC_S_Scope'];
    $_SESSION['Upper_Limit']=$_POST['Upper_Limit'];
    $_SESSION['Lower_Limit']=$_POST['Lower_Limit'];

    header("location:../../views/QC/QC_Setting.php");
};

    // 清除
if (isset($_POST["resetpSetting"])) {

    unset($_SESSION['QC_Scope_id']);
    unset($_SESSION['QC_Tool']);
    unset($_SESSION['QC_List_No']);
    unset($_SESSION['QC_Scope']);
    unset($_SESSION['QC_S_Scope']);
    unset($_SESSION['Upper_Limit']);
    unset($_SESSION['Lower_Limit']);
    unset($_SESSION['opne_qc_setting_right']);

    header("Location:../../views/QC/QC_Setting.php");
}