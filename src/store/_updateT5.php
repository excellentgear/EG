<?php
 ini_set('display_errors', 1);
 ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

 if (!isset($_SESSION)){
    session_start();
}
include("../../src/common/_config.php"); 

// 安全地獲取參數
$pti = isset($_GET['pti']) && is_numeric($_GET['pti']) ? (int)$_GET['pti'] : 0;
if ($pti === 0) {
    die("Invalid pti parameter.");
}
$vw_pti = 'vw_pti_' . $pti . '_list';
$new_machine_id = $_POST['machine_id'];
$bom_ing_fid_to_update = $_GET['bom_ing_fid'];

// 當 processing_sequence 未填寫或為空字串時，設定為 null
$new_processing_sequence = isset($_POST['processing_sequence']) && trim($_POST['processing_sequence']) !== '' ? trim($_POST['processing_sequence']) : null;

// 在所有操作開始前，先查詢這筆資料的「原始狀態」
$old_state_stmt = $db->prepare("SELECT machine_id, processing_sequence FROM bom_ing WHERE bom_ing_fid = :bom_ing_fid");
$old_state_stmt->execute([':bom_ing_fid' => $bom_ing_fid_to_update]);
$old_state = $old_state_stmt->fetch(PDO::FETCH_ASSOC);

$old_machine_id = $old_state ? $old_state['machine_id'] : $_GET['mi']; // Fallback to GET param if not found
$old_processing_sequence = $old_state ? $old_state['processing_sequence'] : null;


    if ($_GET['pti'] == 1) {
        $maker_id = '%原一%';
    } else {
        $maker_id = '%超正%';
    };
    
    // ===== 主要更新邏輯開始 =====
    $db->beginTransaction();
    try {
    if($pti==12){
        if($_GET['pmi']==1){
            // 排機台 (使用參數化查詢修復 SQL 注入)
            // $sql = "UPDATE bom_ing SET PS2=:PS2,processing_sequence = :seq, machine_id = :machine_id, Modified_By = :user_id WHERE bom_ing.bom_ing_fid = :bom_ing_fid";
            $sql = "UPDATE bom_ing SET PS2=:PS2, machine_id = :machine_id, Modified_By = :user_id WHERE bom_ing.bom_ing_fid = :bom_ing_fid";
            $cmd = $db->prepare($sql);
            $cmd->execute([
                // ':seq' => $new_processing_sequence,
                ':machine_id' => $new_machine_id,
                ':user_id' => $_SESSION['id'],
                ':bom_ing_fid' => $bom_ing_fid_to_update,
                ':PS2' => $_POST['PS2']
            ]);
        } else {
            // 變更資料 (使用參數化查詢修復 SQL 注入)
            $sql = "UPDATE bom_ing SET processing_sequence=:seq,single_bet_ps=:single_bet_ps, machine_id=:machine_id, sqty=:sqty, ps=:ps, Modified_By=:user_id, PS2=:ps2 , pti01_ps=:pti01_ps WHERE bom_ing.bom_ing_fid=:bom_ing_fid";
            $cmd = $db->prepare($sql);
            $cmd->bindValue(':seq', $new_processing_sequence, $new_processing_sequence === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $cmd->bindValue(':machine_id', $new_machine_id);
            $cmd->bindValue(':sqty', $_POST['sqty']);
            $cmd->bindValue(':ps', $_POST['ps']);
            $cmd->bindValue(':user_id', $_SESSION['id']);
            $cmd->bindValue(':ps2', $_POST['PS2']);
            $cmd->bindValue(':pti01_ps', $_POST['pti01_ps']);
            $cmd->bindValue(':bom_ing_fid', $_GET['bom_ing_fid']);
            $cmd->bindValue(':single_bet_ps', $_POST['single_bet_ps']);
            $cmd->execute();

            $cmdbom = $db->prepare("UPDATE bom SET Client_Name = :client_name, Modified_By = :user_id WHERE bom.bom = :bom");
            $cmdbom->execute([
                ':client_name' => $_POST['Client_Name'],
                ':user_id' => $_SESSION['id'],
                ':bom' => $_POST['bom']
            ]);
        }
    }else{
        // $pti<>12
        if ($_GET['pmi'] == 1) {
            // 排機台 (使用參數化查詢)
            // $sql = "UPDATE bom_ing SET processing_sequence = :seq, machine_id = :machine_id, Modified_By = :user_id, PS2 = :PS2 WHERE bom_ing.bom_ing_fid = :bom_ing_fid";
            $sql = "UPDATE bom_ing SET  machine_id = :machine_id, Modified_By = :user_id, PS2 = :PS2 WHERE bom_ing.bom_ing_fid = :bom_ing_fid";
            $cmd = $db->prepare($sql);
            $cmd->execute([
                // ':seq' => $new_processing_sequence,
                ':machine_id' => $new_machine_id,
                ':user_id' => $_SESSION['id'],
                ':bom_ing_fid' => $bom_ing_fid_to_update,
                ':PS2' => $_POST['PS2']
            ]);
        } else {
            // 變更資料 (使用參數化查詢)
            $sql = "UPDATE bom_ing SET processing_sequence = :seq, single_bet_ps=:single_bet_ps,machine_id = :machine_id, sqty = :sqty, ps = :ps, Modified_By = :user_id, PS2 = :ps2, pti01_ps = :pti01_ps WHERE bom_ing.bom_ing_fid = :bom_ing_fid";
            $cmd = $db->prepare($sql);
            $cmd->bindValue(':seq', $new_processing_sequence, $new_processing_sequence === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $cmd->bindValue(':machine_id', $new_machine_id);
            $cmd->bindValue(':sqty', $_POST['sqty']);
            $cmd->bindValue(':ps', $_POST['ps']);
            $cmd->bindValue(':user_id', $_SESSION['id']);
            $cmd->bindValue(':ps2', $_POST['PS2']);
            $cmd->bindValue(':pti01_ps', $_POST['pti01_ps']);
            $cmd->bindValue(':bom_ing_fid', $_GET['bom_ing_fid']);
            $cmd->bindValue(':single_bet_ps', $_POST['single_bet_ps']);
            $cmd->execute();

            $cmdbom = $db->prepare("UPDATE bom SET Client_Name = :client_name, Modified_By = :user_id WHERE bom.bom = :bom");
            $cmdbom->execute([
                ':client_name' => $_POST['Client_Name'],
                ':user_id' => $_SESSION['id'],
                ':bom' => $_POST['bom']
            ]);
        }
    }
    
    // --- 全新的、更穩健的重新排序邏輯 ---
    if (empty($_GET['pmi']) || $_GET['pmi'] != 1) {
        // 1. 先將要移動的項目從排序中暫時移除，避免主鍵衝突
        $stmt_set_null = $db->prepare("UPDATE bom_ing SET processing_sequence = NULL WHERE bom_ing_fid = :bom_ing_fid");
        $stmt_set_null->execute([':bom_ing_fid' => $bom_ing_fid_to_update]);

        // 2. 如果換了機台，需要重新整理舊機台的順序以填補空缺
        if ($old_machine_id != $new_machine_id && !is_null($old_machine_id)) {
            $reorder_old_stmt = $db->prepare("UPDATE bom_ing SET processing_sequence = (@rn:=@rn+1) WHERE machine_id = :old_machine_id AND processing_sequence IS NOT NULL AND (@rn:=0) = 0 ORDER BY CAST(processing_sequence AS UNSIGNED)");
            $reorder_old_stmt->execute([':old_machine_id' => $old_machine_id]);
        }

        // 3. 在新機台上，為要插入的項目騰出空間 (將所有大於等於新順序的項目+1)
        if ($new_processing_sequence !== null) {
            $stmt_make_space = $db->prepare("UPDATE bom_ing SET processing_sequence = processing_sequence + 1 WHERE machine_id = :new_machine_id AND processing_sequence >= :new_seq");
            $stmt_make_space->execute([':new_machine_id' => $new_machine_id, ':new_seq' => $new_processing_sequence]);
        }

        // 4. 最後，將目標項目更新到正確的機台與順序
        $stmt_final_update = $db->prepare("UPDATE bom_ing SET machine_id = :new_machine_id, processing_sequence = :new_seq WHERE bom_ing_fid = :bom_ing_fid");
        $stmt_final_update->execute([
            ':new_machine_id' => $new_machine_id,
            ':new_seq' => $new_processing_sequence,
            ':bom_ing_fid' => $bom_ing_fid_to_update
        ]);
    }

    $db->commit();

} catch (Exception $e) {
    $db->rollBack();
    // 記錄錯誤並導向錯誤頁面
    error_log("Update T5 Error: " . $e->getMessage());
    header("location:../../views/pm/schedule_T5.php?pti=".$_GET['pti']."&id=".$_GET['id']."&message=" . urlencode("更新失敗: " . $e->getMessage()));
    exit;
}

header("location:../../views/pm/schedule_T5.php?ps=".$maker_id.$new_processing_sequence."&pti=".$_GET['pti']."&id=".$_GET['id']."&message=success");
