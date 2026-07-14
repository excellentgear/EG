<?php
 if (!isset($_SESSION)){
    session_start();
    }
    include("../common/_config.php");
//2024.03.19 ok

    //確認最後一筆順序編號
    $QC_TOTAL=$_GET['QC_TOTAL'];
    $QC_TOTAL=$QC_TOTAL+1;

    //新增一格
    $conn = $db->prepare("INSERT INTO qc_scope SET `QC_Id`='$_GET[QC_Id]',`Created_By`='$_SESSION[id]'");
    $conn->execute();

    //QC_TOTAL+1
    $sth = $db->prepare("UPDATE qc SET QC_TOTAL='$QC_TOTAL',Modified_By='$_SESSION[id]' where QC_Id='$_GET[QC_Id]'");
    $sth->execute();

    $_SESSION['QC_TOTAL'] = $QC_TOTAL;
    

    header("Location:../../views/QC/QC_Setting.php");

