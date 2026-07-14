<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../common/_config.php");
//2024.03.20 ok

if (isset($_POST['QC_TOTAL_Setting'])){ //更新  OK\

    //忽略筆數 只新增一筆
    $Ok="";
    if ($_POST['QC_Skip']==1){
        $QC_TOTAL=null;
        $Ok=1;
    } else {
        $_POST['QC_Skip']=null;
        $QC_TOTAL=1;
        $Ok=1;
    };

    //依筆數自動新增空白資料
    // $Ok="";
    // if ($_POST['QC_Skip']=="" && $_POST['QC_TOTAL']==""){
    //     header("Location:../../views/QC/QC_Setting.php?message=資料不齊全 請填寫完整");
    // } else if ($_POST['QC_Skip']==1){
    //     $_POST['QC_TOTAL']=null;
    //     $Ok=1;
    // } else if ($_POST['QC_TOTAL']!=""){
    //     $_POST['QC_Skip']=null;
    //     $Ok=1;
    // } else {
    // };

    if ($_POST['ProcessNo_option']=='0' or $_POST['ProcessNo_option']==null){
        if($_POST['ProcessNo']==null){
            header("Location:../../views/QC/QC_Setting.php?message=資料不齊全 請填寫完整");
        };
    } else {
        $_POST['ProcessNo']=$_POST['ProcessNo_option'];
    };

    

    $d_id="";
    $d_id = $_SESSION['d_id'];

    $cmd = $db->prepare("SELECT * FROM qc WHERE d_id='$d_id' and ProcessNo='$_POST[ProcessNo]'");
    $cmd->execute();
    $row = $cmd->fetch();

    $QC_ID=$row['QC_Id'];

    if($QC_ID !=null){ //查詢有資料 
        if ($_POST['QC_Type']=='0'){
            $sth = $db->prepare("UPDATE qc SET QC_V='$_POST[QC_V]',QC_Skip='$_POST[QC_Skip]'
            ,Modified_By='$_SESSION[id]' where QC_Id='$QC_ID'");
            $sth->execute();
        } else {
            $sth = $db->prepare("UPDATE qc SET QC_V='$_POST[QC_V]',QC_Skip='$_POST[QC_Skip]',
            QC_Type='$_POST[QC_Type]',Modified_By='$_SESSION[id]' where QC_Id='$QC_ID'");
            $sth->execute();
        };

        $cmd = $db->prepare("SELECT * FROM qc WHERE d_id='$d_id' and ProcessNo='$_POST[ProcessNo]'");
        $cmd->execute();
        $row = $cmd->fetch();
        
        $_SESSION['QC_Id']       =$row['QC_Id'];
        $_SESSION['d_id']        =$row['d_id'];
        $_SESSION['D_Setting_Id']=$row['D_Setting_Id'];
        $_SESSION['QC_V']        =$row['QC_V'];
        $_SESSION['QC_Skip']     =$row['QC_Skip'];
        $_SESSION['QC_TOTAL']    =$row['QC_TOTAL'];
        $_SESSION['ProcessNo']   =$row['ProcessNo'];
        $_SESSION['QC_Type']     =$row['QC_Type'];

        header("Location:../../views/QC/QC_Setting.php?message=success&d_id=".$d_id."&ok=".$Ok."&QC_ID=".$QC_ID."&ProcessNo=".$_POST['ProcessNo']);

    } else if ($_SESSION['d_id'] !="" && $Ok=1){ //新增資料   底下測試OK 
         
        //忽略筆數 只新增一筆
            $sth = $db->prepare("INSERT INTO qc SET d_id='$d_id',QC_V='$_POST[QC_V]',QC_Skip='$_POST[QC_Skip]',
            QC_TOTAL=$QC_TOTAL,ProcessNo='$_POST[ProcessNo]',QC_Type='$_POST[QC_Type]',Created_By='$_SESSION[id]'");
        //依筆數自動新增空白資料
            // $sth = $db->prepare("INSERT INTO qc SET d_id='$d_id',QC_V='$_POST[QC_V]',QC_Skip='$_POST[QC_Skip]',
            // QC_TOTAL='$_POST[QC_TOTAL]',ProcessNo='$_POST[ProcessNo]',QC_Type='$_POST[QC_Type]',Created_By='$_SESSION[id]'");

            $sth->execute();

            $cmd = $db->prepare("SELECT * FROM qc WHERE d_id='$d_id' and ProcessNo='$_POST[ProcessNo]'");
            $cmd->execute();
            $row = $cmd->fetch();
            $QC_Id=$row['QC_Id'];

            $i=1;
            do{
                //依筆數自動新增空白資料
                // $conn = $db->prepare("INSERT INTO qc_scope SET `QC_Id`='$QC_Id', `QC_List_No`='$i',`Created_By`='$_SESSION[id]'");

                $conn = $db->prepare("INSERT INTO qc_scope SET `QC_Id`='$QC_Id',`Created_By`='$_SESSION[id]'");
                $conn->execute();
                $i=$i+1;
            
            }while ($i <= $QC_TOTAL);

            $_SESSION['QC_Id']       =$row['QC_Id'];
            $_SESSION['d_id']        =$row['d_id'];
            $_SESSION['D_Setting_Id']=$row['D_Setting_Id'];
            $_SESSION['QC_V']        =$row['QC_V'];
            $_SESSION['QC_Skip']     =$row['QC_Skip'];
            $_SESSION['QC_TOTAL']    =$row['QC_TOTAL'];
            $_SESSION['ProcessNo']   =$row['ProcessNo'];
            $_SESSION['QC_Type']     =$row['QC_Type'];
            
            header("Location:../../views/QC/QC_Setting.php?message=success");
    } else {
            header("Location:../../views/QC/QC_Setting.php?message=資料不齊全 請填寫完整");
    }
}

// 新增新製程
if (isset($_POST["newQC_setting"])) {

    //左側欄
    unset($_SESSION['QC_Id']);
    unset($_SESSION['QC_V']);
    unset($_SESSION['QC_Skip']);
    unset($_SESSION['QC_TOTAL']);

    //右側欄
    unset($_SESSION['opne_qc_setting_right']);

    unset($QC_Id);
    unset($QC_V); //版本

    header("Location:../../views/QC/QC_Setting.php");
}

// 清除
if (isset($_POST["resetpSetting"])) {

    //左側欄
    unset($_SESSION['QC_Id']);
    unset($_SESSION['d_id']);
    unset($_SESSION['QC_V']);
    unset($_SESSION['QC_Skip']);
    unset($_SESSION['QC_TOTAL']);
    unset($_SESSION['ProcessNo']);
    unset($_SESSION['QC_Type']);

    //右側欄
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

// 刪除
if (isset($_POST["QC_TOTAL_Del"])) {

    $_SESSION['QC_TOTAL_Del']=9;

    header("Location:../../views/QC/QC_Setting.php?message=請確認是否刪除 製程 ".$_POST['ProcessNo']);
}
// 確認刪除
if (isset($_POST["QC_TOTAL_Del_dbCHECK"])) {

    $QCDEL = $db->prepare("DELETE FROM qc_scope WHERE `QC_Id`=".$_POST['QC_Id']);
    $QCDEL->execute();

    $cmd = $db->prepare("DELETE FROM qc WHERE `QC_Id`=".$_POST['QC_Id']);
    $cmd->execute();

    unset($_SESSION['QC_TOTAL_Del']);
    unset($_SESSION['d_id']);
    unset($_SESSION['QC_Id']);
    unset($_SESSION['QC_V']);
    unset($_SESSION['QC_Skip']);
    unset($_SESSION['QC_TOTAL']);
    unset($_SESSION['ProcessNo']);
    unset($_SESSION['QC_Type']);
    unset($_SESSION['opne_qc_setting_right']);

    header("Location:../../views/QC/QC_Setting.php?message=已刪除製程編號 ".$_POST['ProcessNo']);
}