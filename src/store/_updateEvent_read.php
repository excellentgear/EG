<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php");
include("../../src/common/homepage.php"); // 首頁導向共用邏輯（個人 > 部門 > 全域預設）

    $cmd = $db->prepare("INSERT INTO live_event_for_user set user_id =".$_GET['id']." ,live_event_id=".$_GET['eventid']." ,oready_read=1");
    $cmd->execute();
    $row = $cmd->fetch();


    // 依「個人 → 部門 → 全域預設」首頁設定導向（個人指派優先）；未設定才退回下方 switch
    $home = hp_resolve_home_page($db, (int)$_GET['id']);
    if ($home) {
        header("Location:../../" . $home);
        exit;
    }
    switch($_GET['status']){
        case 9: //超級管理員
            header("Location:../../views/admin/index.php");
        break;
        case 1: //管理部
            header("Location:../../views/admin/dashboard.php");
        break;
        case 2: //生管（原導向已封存的 teacher_dashboard，改退回一般儀表板）
            header("Location:../../views/admin/dashboard.php");
        break;
        case 3: //製造部（原導向已封存的 stu_dashboard，改退回一般儀表板）
            header("Location:../../views/admin/dashboard.php");
        break;
        case 5: //管理員
            header("Location:../../views/admin/dashboard.php");
        break;}
// header("location:../../views/liveEvent/createEvent.php?eventid=".$_GET['eventid'].'&id='.$_GET['id']);