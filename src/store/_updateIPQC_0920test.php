<?php
 if (!isset($_SESSION)){
    session_start();
    }
    include("../../src/common/_config.php"); 

    $bom_ing_id=$_GET['bom_ing_id'];

    $cmd = $db->prepare("SELECT bom_ing.bom_ing_id,bom.bom,bom.d_id,DATE_FORMAT(bom_ing.outsource_date,'%m/%d')AS outsource_date,
process_no.ProcessNo, process_no.ProcessName,bom_ing.maker_id,bom_ing.sqty,bom.Client_Name 
FROM bom_ing
LEFT JOIN process_no ON process_no.ProcessNo=bom_ing.process_no
LEFT JOIN bom ON Bom.bom=bom_ing.bom
WHERE bom_ing.bom_ing_id='$bom_ing_id'
ORDER BY bom_ing.outsource_date");

    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['bom']            = $row['bom'];
    $_SESSION['sqty']           = $row['sqty'];
    $_SESSION['d_id']           = $row['d_id'];
    $_SESSION['outsource_date'] = $row['outsource_date'];
    $_SESSION['bom_ing_id']     = $row['bom_ing_id'];
    $_SESSION['ProcessName']    = $row['ProcessName'];
    $_SESSION['ProcessNo']      = $row['ProcessNo'];
    $_SESSION['maker_id']       = $row['maker_id'];
    $_SESSION['Client_Name']    = $row['Client_Name']; //客戶名稱

    header("location:../../views/reply/reply.php");
