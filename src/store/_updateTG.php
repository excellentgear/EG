<?php
 if (!isset($_SESSION)){
    session_start();
}
include("../../src/common/_config.php"); 

$pti=$_GET['pti'];
$vw_pti='vw_pti_'.$pti.'_list';
$machine_id=$_POST['machine_id'];
$old_machine_id=$_GET['mi'];
$processing_sequence=$_POST['processing_sequence'];

if ($_GET['pti'] == 1) {
    $maker_id = '%原一%';
} else {
    $maker_id = '%超正%';
};

// 查詢本機台筆數
    // 準備 SQL 查詢
    $sql = "SELECT COUNT(*) AS total_count FROM $vw_pti WHERE `machine_id` = :machine_id";

    // 準備並執行查詢
    $stmt = $db->prepare($sql);
    $stmt->execute([':machine_id' => $machine_id]);

    // 獲取結果
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $count_machine_id = $result['total_count'];


IF($old_machine_id==$machine_id AND $processing_sequence==$count_machine_id){
    
}else{
    $processing_sequence=$processing_sequence-1;
};

if($_GET['pmi']==1){

    // 排機台
    // $cmd = $db->prepare("UPDATE bom_ing SET processing_sequence='$processing_sequence',
    // machine_id='$_POST[machine_id]',Modified_By='$_SESSION[id]' 
    // WHERE bom_ing.bom_ing_fid='$_GET[bom_ing_fid]'");
    $cmd = $db->prepare("UPDATE bom_ing SET processing_sequence=$count_machine_id+1,
    machine_id='$_POST[machine_id]',Modified_By='$_SESSION[id]' 
    WHERE bom_ing.bom_ing_fid='$_GET[bom_ing_fid]'");

} else {

    // 變更資料
    $cmd = $db->prepare("UPDATE bom_ing SET processing_sequence='$processing_sequence',
    machine_id='$_POST[machine_id]',sqty='$_POST[sqty]',ps='$_POST[ps]',Modified_By='$_SESSION[id]' 
    WHERE bom_ing.bom_ing_fid='$_GET[bom_ing_fid]'");

}
    $cmd->execute();

    // 重新排序相同新 machine_id 的 processing_sequence 值
    $db->exec("SET @row_number = 0;");
    $reorderNewSql = "UPDATE bom_ing 
                      SET processing_sequence = (@row_number:=@row_number + 1) 
                      WHERE machine_id = :machine_id 
                      ORDER BY processing_sequence ASC";
    $stmtNew = $db->prepare($reorderNewSql);
    $stmtNew->execute([':machine_id' => $machine_id]);

    // 重新排序相同舊 machine_id 的 processing_sequence 值
    $db->exec("SET @row_number = 0;");
    $reorderOldSql = "UPDATE bom_ing 
                      SET processing_sequence = (@row_number:=@row_number + 1) 
                      WHERE machine_id = :machine_id 
                      ORDER BY processing_sequence ASC";
    $stmtOld = $db->prepare($reorderOldSql);
    $stmtOld->execute([':machine_id' => $old_machine_id]);


// 新增TG(同時新增bom 與 bom_ing)
if (isset($_POST["newTG"])) {

    $bom_ing_id = substr($_POST['bom'], 3, 9)."-12-".$_POST['sqty'];

    $sth = $db->prepare("INSERT INTO `bom_ing`(`bom_ing_id`,`bom`,
    `machine_id`,`process_no`,`maker_id`,`sqty`,`processing_sequence`,
    `processing_state`,`ps`,`outsource_date`,`return_date`,`Created_By`,`Created_At`
    ) VALUES ('$bom_ing_id','$_POST[bom]','$_POST[machine_id]','12','超正齒研','$_POST[sqty]',
    $count_machine_id+1,'ing'','$_POST[ps]',NOW(),null,'$_get[id]',NOW())");
    $sth->execute();

    $cmd = $db->prepare("INSERT INTO `bom`(`bom`, `bom_ing_id`, `d_id`, `specification`, `sqty`, 
    `Client_Name`, `state`, `processing_state`, `Created_By`, `Created_At`) 
    VALUES ('$_POST[bom]','$bom_ing_id','$_POST[d_id]','$_POST[specification]','$_POST[sqty]',
    '$_POST[Client_Name]','ing',null,'$_get[id]',NOW())");
    $cmd->execute();

    unset($_SESSION['bom']);
    unset($_SESSION['machine_id']);
    unset($_SESSION['sqty']);
    unset($_SESSION['processing_sequence']);
    unset($_SESSION['ps']);
    unset($_SESSION['qty']);
    unset($_SESSION['Client_Name']);

    // 重新排序相同 新machine_id 的 processing_sequence 值
    $stmt->execute([':machine_id' => $machine_id]);

    // 重新排序相同 old_machine_id 的 processing_sequence 值
    $stmtOld->execute([':machine_id' => $old_machine_id]);
};

header("location:../../views/pages/schedule_TG.php?ps=".$_POST['processing_sequence']."&pti=".$_GET['pti']."&id=".$_GET['id']."&message=success");
