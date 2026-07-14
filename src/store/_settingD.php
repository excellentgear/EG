<?php
    session_start();

include_once '../common/DBConnection.php';
include '../common/_config.php';

if (isset($_POST['DSetting_UpDate'])){
    $user_name="";
    $user_name = trim($_POST['D_Setting_Id']);
    // header("Location:../../views/QC/D_Setting.php?message=".$_POST['D_Setting_Id']);
    $dbConnection = new DBConnection();
    $result = $dbConnection->getall('SELECT D_Setting_Id FROM `d_setting` where D_Setting_Id ="'.$user_name.'"');

    foreach ($result as $row){
        $admin = $row;
    }

    $_SESSION['D_Setting_Id'] = $admin['D_Setting_Id'];
    if ($user_name == $_SESSION['D_Setting_Id']) {
        $sth = $db->prepare("UPDATE d_setting SET Spec_No='$_POST[Spec_No]',Drawing_No='$_POST[Drawing_No]',Client_Name='$_POST[Client_Name]',Modified_By='$_SESSION[id]' WHERE D_Setting_Id ='$_SESSION[D_Setting_Id]'");
        $sth->execute();
        header("Location:../../views/pm/D_Setting.php?message=success");
    } else {
        $sth = $db->prepare("INSERT INTO `d_setting`(`D_Setting_Id`,`Drawing_No`,`Spec_No`,`Client_Name`,`Created_By`)
                            VALUES('$_POST[D_Setting_Id]','$_POST[Drawing_No]','$_POST[Spec_No]','$_POST[Client_Name]','$_SESSION[id]')");   
        $sth->execute();
        header("Location:../../views/pm/D_Setting.php?message=success");
    }
}

// 清除
if (isset($_POST["resetpSetting"])) {

    unset($_SESSION['d_setting_Del']);
    unset($_SESSION['d_id']);
    unset($_SESSION['D_Setting_Id']);
    unset($_SESSION['Drawing_No']); //料號
    unset($_SESSION['Spec_No']); //規格
    unset($_SESSION['Client_Name']); //客戶名稱
    header("Location:../../views/pm/D_Setting.php");
}

// 確認刪除
if (isset($_POST["d_setting_Del_dbCHECK"])) {

    $conn = new DBConnection();

    $students = $conn->execute("DELETE FROM d_setting WHERE d_id ='".$_GET['d_id']."'");

    unset($_SESSION['d_setting_Del']);
    unset($_SESSION['d_id']);
    unset($_SESSION['D_Setting_Id']);
    unset($_SESSION['Drawing_No']); //料號
    unset($_SESSION['Spec_No']); //規格
    unset($_SESSION['Client_Name']); //客戶名稱

    header("location:../../views/pm/D_Setting.php");
    
}