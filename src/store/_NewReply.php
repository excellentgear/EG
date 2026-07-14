<?php
 if (!isset($_SESSION)){
    session_start();
    }
    include("../common/_config.php");

// 新增 2024.10.22 確認ok
if (isset($_POST['reply_other_New'])){
        
    if($_POST['NG']==""){
        $ng=0;
    } else {
        $ng=$_POST['NG'];
    }

    if($_POST['NG2']==""){
        $ng2=0;
    } else {
        $ng2=$_POST['NG2'];
    }

    if($_POST['NG3']==""){
        $ng3=0;
    } else {
        $ng3=$_POST['NG3'];
    }

    if($_POST['ps']==""){
        $ps='null';
    }

    if($_POST['oready_sqty']==""){
        $oready_sqty=$_POST['sqty'];
    } else {
        $oready_sqty=$_POST['oready_sqty'];
    }

    $OK=$oready_sqty-$ng-$ng2-$ng3;

// Debug: Print all POST values
error_log("POST values received:");
error_log(print_r($_POST, true));

// Get values from form submission
$delivery_date = isset($_POST['delivery_date']) ? $_POST['delivery_date'] : '';
$quantity = isset($_POST['quantity']) ? $_POST['quantity'] : '';

// Debug: Print processed values
error_log("Processed values:");
error_log("Delivery date: " . $delivery_date);
error_log("Quantity: " . $quantity);

if($_POST['replydate']==""){
    $replydate=date("Y-m-d");
} else {
    $replydate=$_POST['replydate'];
}

        // 製程=12
        IF ($_POST['ProcessNo']==12){

            $cmd = $db->prepare("SELECT max(`reply_id`) FROM reply");
                $cmd->execute();
                $row = $cmd->fetch();

                $_SESSION['reply_id']=$row['max(`reply_id`)'];

                IF ($_POST['m']==""){$m="NULL";} else {$m=$_POST['m'];};
                IF ($_POST['t']==""){$t="NULL";} else {$t=$_POST['t'];};
                IF ($_POST['width']==""){$width="NULL";} else {$width=$_POST['width'];};
                IF ($_POST['mc_id']==""){$mc_id="NULL";} else {$mc_id=$_POST['mc_id'];};
                IF ($_POST['mc_time']==""){$mc_time="NULL";} else {$mc_time=$_POST['mc_time'];};
                IF ($_POST['processing_time']==""){$processing_time="NULL";} else {$processing_time=$_POST['processing_time'];};

            $sth = $db->prepare("INSERT INTO reply_all(bom_ing_id,`BOM`,`ps`,`ProcessNo`,`sqty`,`oready_sqty`,`ok_sqty`,`ng_sqty`,`ng_id`,`ng_sqty2`,`ng_id2`,`ng_sqty3`,`ng_id3`,`MakerId`,`m`,`t`,`width`,`mc_id`,`mc_time`,`machine_id`,`processing_time`,`Created_By`,`Created_At`)
                VALUES('$_POST[bom_ing_id]','$_POST[BOM]','$_POST[ps]','$_POST[ProcessNo]','$_POST[sqty]',$oready_sqty,$OK,$ng,'$_POST[NG_id]',$ng2,'$_POST[NG_id2]',$ng3,'$_POST[NG_id3]','$_POST[MakerId]','$m','$t','$width','$mc_id','$mc_time','$_POST[machine_id]','$processing_time','$_SESSION[id]','$replydate')");   
                $sth->execute();    
        }else{

            $sth = $db->prepare("INSERT INTO `reply_all`(bom_ing_id,`BOM`,`ps`,`ProcessNo`,`sqty`,`oready_sqty`,`ok_sqty`,`ng_sqty`,`ng_id`,`ng_sqty2`,`ng_id2`,`ng_sqty3`,`ng_id3`,`MakerId`,`Created_By`,`Created_At`)
            VALUES('$_POST[bom_ing_id]','$_POST[BOM]','$_POST[ps]','$_POST[ProcessNo]','$_POST[sqty]',$oready_sqty,$OK,$ng,'$_POST[NG_id]',$ng2,'$_POST[NG_id2]',$ng3,'$_POST[NG_id3]','$_POST[MakerId]','$_SESSION[id]','$replydate')");   
            $sth->execute();

        }

    header("Location:../../views/reply/reply_other.php?message=success&ps=".$ps."&t="."&mc_time=".$mc_time."&processing_time=".$processing_time."&ri=".$_SESSION['reply_id']."&C=".$_SESSION['C']."");
    
    };

// 修改 2024.10.21->確認OK
    if (isset($_POST['reply_other_update'])){   

if($_POST['NG']==""){
        $ng=0;
    } else {
        $ng=$_POST['NG'];
    }

    if($_POST['NG2']==""){
        $ng2=0;
    } else {
        $ng2=$_POST['NG2'];
    }

    if($_POST['NG3']==""){
        $ng3=0;
    } else {
        $ng3=$_POST['NG3'];
    }

    // if($_POST['ps']==""){
    //     $ps='null';
    // }

    if($_POST['oready_sqty']==""){
        $oready_sqty=$_POST['sqty'];
    } else {
        $oready_sqty=$_POST['oready_sqty'];
    }

        $OK=$oready_sqty-$ng-$ng2-$ng3;

        if($_POST['replydate']==""){
            $replydate=date("Y-m-d");
        } else {
            $replydate=$_POST['replydate'];
        }

        IF ($_POST['ProcessNo']!=12){
            $m="NULL";
            $t="NULL";
            $width="NULL";
            $mc_id="NULL";
            $mc_time="NULL";
            $processing_time="NULL";
        }else{
            IF ($_POST['m']==""){$m="NULL";} else {$m=$_POST['m'];};
            IF ($_POST['t']==""){$t="NULL";} else {$t=$_POST['t'];};
            IF ($_POST['width']==""){$width="NULL";} else {$width=$_POST['width'];};
            IF ($_POST['mc_id']==""){$mc_id="NULL";} else {$mc_id=$_POST['mc_id'];};
            IF ($_POST['mc_time']==""){$mc_time="NULL";} else {$mc_time=$_POST['mc_time'];};
            IF ($_POST['processing_time']==""){$processing_time="NULL";} else {$processing_time=$_POST['processing_time'];};
        }

        $sth = $db->prepare("UPDATE reply_all SET
                            `ps`='$_POST[ps]',
                            `oready_sqty`=$oready_sqty,
                            `ok_sqty`=$OK,
                            `ng_sqty`=$ng,`ng_id`='$_POST[NG_id]',
                            `ng_sqty2`=$ng2,`ng_id2`='$_POST[NG_id2]',
                            `ng_sqty3`=$ng3,`ng_id3`='$_POST[NG_id3]',
                            `m`='$m',
                            `t`='$t',
                            `width`='$width',
                            `mc_id`='$mc_id',
                            `mc_time`='$mc_time',
                            `machine_id`='$_POST[machine_id]',
                            `processing_time`='$processing_time',
                            `Created_At`='$replydate',
                            `Modified_By`='$_SESSION[id]',
                            `Modified_At`= CURRENT_TIMESTAMP
                            WHERE `reply_id` ='$_POST[reply_id]'");  
        $sth->execute();

  
    header("Location:../../views/reply/reply_other.php?mc=".$machine_id."message=updatesuccess&pti=".$_GET['pti']."&bi=".$_POST['bom_ing_id']."&BOM=".$_POST['BOM']."&d=".$_POST['d_id']."&pna=".$_POST['ProcessName']."&ProcessNo=".$_POST['ProcessNo']."&MakerId=".$_POST['MakerId']."&sqty=".$_POST['sqty']."&C=".$_SESSION['C']."");
};



// 清除
    if (isset($_POST["reply_other_resetpSetting"])) {

        unset($_SESSION['BOM']);
        unset($_SESSION['d']);
        unset($_SESSION['bom_ing_id']);
        unset($_SESSION['ProcessNo']);
        unset($_SESSION['stqy']); 
        unset($_SESSION['oready_sqty']); 
        unset($_SESSION['Client_Name']); 
        unset($_SESSION['NG']); 
        unset($_SESSION['NG2']); 
        unset($_SESSION['NG3']); 
        
        header("Location:../../views/reply/reply_other.php");
    }