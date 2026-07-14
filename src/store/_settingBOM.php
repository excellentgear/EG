<?php
    session_start();

include_once '../common/DBConnection.php';
include '../common/_config.php';
//2024.03.26 OK

//查詢
if (isset($_POST['BOMSetting_checkBom'])){
    $cmd = $db->prepare("SELECT bom,Client_Name,sqty 
                            FROM `bom` 
                            WHERE bom='".$_POST['BOM_set_list']."'");
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['bom']         = $row['bom'];
    $_SESSION['sqty']        = $row['sqty'];
    $_SESSION['d_id']        = $row['d_id'];
    $_SESSION['Client_Name'] = $row['Client_Name']; //客戶名稱

    header("location:../../views/pm/BOM_Setting.php?bt=bcb");
};

//帶出與寫入
if (isset($_POST['BOMSetting_UpDate'])){
    unset($_SESSION['bom']);
    $pattern = "/^B\-\d{10}$/";
    $string = $_POST['BOM'];
    preg_match($pattern, $string, $matches);
    $M=$matches[0]; // B-000000000
    IF($M==null){
        header("Location:../../views/pm/BOM_Setting.php?message=BOM 格式錯誤 或 資料有誤");
    } else {
        $today = date('Y-m-d H:i:s');
        $bom="";
        $bom = trim($_POST['BOM']);
        $dbConnection = new DBConnection();
        $result = $dbConnection->getall('SELECT bom FROM BOM where bom="'.$bom.'"');
        foreach ($result as $row){
            $admin = $row;
        
        $_SESSION['BOM'] = $admin['bom'];
        };
        if ($bom == $_SESSION['BOM']) {
            if($_GET['bt']='bcb'){
                // 更新現有資料

                    // 撈現有bom sqty
                    $cmd = $db->prepare("SELECT bom,sqty 
                    FROM `bom` 
                    WHERE bom='".$bom."'");
                    $cmd->execute();
                    $row = $cmd->fetch();
                    $old_sqty = $row['sqty'];

                $sth = $db->prepare("UPDATE `bom` SET
                sqty='$_POST[sqty]',`Modified_By`='$_SESSION[id]',Modified_At='$today'
                WHERE bom='$bom'");
                $sth->execute();
                $_SESSION['show_del']=999;
                $_SESSION['sqty']=$_POST['sqty'];
                $_SESSION['bom']=$bom;

                $bom_ing_id = substr($bom, -9); 
                $combined_id_qty = $bom_ing_id . '--' .$old_sqty;
                $combined_id_qty2 = $bom_ing_id . '--' .$_POST['sqty'];
                $ING = $db->prepare("UPDATE `bom_ing` SET
                bom_ing_id='$combined_id_qty2',sqty='$_POST[sqty]',`Modified_By`='$_SESSION[id]',Modified_At='$today'
                WHERE bom_ing_id='$combined_id_qty'");
                $ING->execute();
                
                header("Location:../../views/pm/BOM_Setting.php?message=success111");
            }else{
                header("Location:../../views/pm/BOM_Setting.php?message=BOM 重複設定");
            }
            
        } else {
            $sth = $db->prepare("INSERT INTO `BOM`(sqty,`bom`,`d_id`,`Client_Name`,`Created_By`,Created_At)
            VALUES('$_POST[sqty]','$bom','$_POST[D_Setting_Id]','$_POST[Client_Name]','$_SESSION[id]','$today')");
            $sth->execute();

            $bom_ing_id = substr($bom, -9); 
            $combined_id_qty = $bom_ing_id . '--' .$_POST['sqty'];
            $ING = $db->prepare("INSERT INTO `bom_ing`(bom_ing_id,processing_state,`bom`,`sqty`,`Created_By`,Created_At)
            VALUES('$combined_id_qty','ing','$bom','$_POST[sqty]','$_SESSION[id]','$today')");
            $ING->execute();

            $_SESSION['show_del']=999;
            unset($_SESSION['bom']);
            header("Location:../../views/pm/BOM_Setting.php?message=success");
        };
    };
};

// 清除
if (isset($_POST["resetpSetting"])) {

    // unset($_SESSION['d_id']);
    // unset($_SESSION['D_Setting_Id']);
    // unset($_SESSION['Drawing_No']); //料號
    // unset($_SESSION['Spec_No']); //規格
    unset($_SESSION['Client_Name']); //客戶名稱
    unset($_SESSION['bom']);
    unset($_SESSION['sqty']);
    unset($_SESSION['bom_Del']);
    unset($_SESSION['show_del']);

    header("Location:_cleanBOM_Setting.php");
};

// 刪除
if (isset($_POST["bom_Del"])) {
    $_SESSION['bom_Del']=9;
    header("location:../../views/pm/BOM_Setting.php?&message=請確認是否刪除 ".$_SESSION['bom']);
};

// 確認刪除
if (isset($_POST["bom_Del_dbCHECK"])) {

    $conn = new DBConnection();

    $sth = $conn->execute("DELETE FROM bom WHERE bom ='".$_POST['BOM']."'");

    $sth = $conn->execute("DELETE FROM bom_ing WHERE bom ='".$_POST['BOM']."'");

    // unset($_SESSION['d_id']);
    // unset($_SESSION['D_Setting_Id']);
    // unset($_SESSION['Drawing_No']); //料號
    // unset($_SESSION['Spec_No']); //規格
    unset($_SESSION['Client_Name']); //客戶名稱
    unset($_SESSION['bom']);
    unset($_SESSION['sqty']);
    unset($_SESSION['bom_Del']);
    unset($_SESSION['show_del']);

    header("location:../../views/pm/BOM_Setting.php?bom=".$_POST['BOM']);
    
}