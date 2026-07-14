<?php
 if (!isset($_SESSION)){
    session_start();
    }
//2024.03.19 ok   若刪除中間再新增，QC_List_No 會出現相同值，只影響排序
    include('../../src/common/DBConnection.php');
    include("../common/_config.php");

    $conn = new DBConnection(); 
    $QC = $conn->execute("DELETE FROM qc_scope WHERE `QC_Scope_id`=".$_GET['QC_Scope_id']);

    $QC_TOTAL=$_GET['QC_TOTAL'];
    $QC_TOTAL=$QC_TOTAL-1;

    //QC_TOTAL-1
    $sth = $db->prepare("UPDATE qc SET QC_TOTAL='$QC_TOTAL',Modified_By='$_SESSION[id]' where QC_Id=".$_GET['QC_Id']);
    $sth->execute();

    $_SESSION['QC_TOTAL'] = $QC_TOTAL;

    header("location:../../views/QC/QC_Setting.php?message=已刪除一筆資料".$_GET['QC_TOTAL']);


   