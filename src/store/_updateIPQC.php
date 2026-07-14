<?php
 if (!isset($_SESSION)){
    session_start();
    }
    include("../../src/common/_config.php"); 

    $bom=$_GET['bom'];
    $d_id=$_GET['d_id'];
    $ProcessNo=$_GET['ProcessNo'];

    $cmd = $db->prepare("SELECT bom_ing.bom,bom_ing.sqty,d_setting.d_id AS dd_id,d_setting.D_Setting_Id,bom.d_id, bom.Client_Name,
                           qc.ProcessNo 
                           FROM bom 
                           LEFT JOIN bom_ing ON bom_ing.bom=bom.bom 
                           LEFT JOIN d_setting ON d_setting.D_Setting_Id=bom.d_id
                           LEFT JOIN qc ON qc.d_id=d_setting.d_id
                           WHERE d_setting.d_id=$d_id and bom_ing.bom='$bom' and qc.ProcessNo=$ProcessNo ORDER BY bom.bom");

    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['bom']         = $row['bom'];
    $_SESSION['sqty']        = $row['sqty'];
    $_SESSION['d_id']        = $row['d_id'];
    $_SESSION['dd_id']       = $row['dd_id'];
    $_SESSION['ProcessNo']   = $row['ProcessNo'];
    $_SESSION['D_Setting_Id']= $row['D_Setting_Id'];
    $_SESSION['Drawing_No']  = $row['Drawing_No']; //料號
    $_SESSION['Spec_No']     = $row['Spec_No']; //規格
    $_SESSION['Client_Name'] = $row['Client_Name']; //客戶名稱

    header("location:../../views/QC/IPQC_Setting.php");
