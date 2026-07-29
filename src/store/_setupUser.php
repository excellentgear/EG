<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

// 自行修改user
if (isset($_POST["updataUserbtn"])  && $_POST["user_password"] != "") {

    // 共用帳號可鎖密碼（ai-rules/13）：鎖定者一律擋下
    // 註：目標 id 一律取整數並以參數帶入，否則 lock 檢查的 id 與實際更新的 id 可能不同而形同虛設
    require_once __DIR__ . '/../common/shared_account_lib.php';
    $__targetId = (int)($_GET['id'] ?? 0);
    if ($__targetId > 0 && eg_shared_password_locked($db, $__targetId)) {
        $msg = '此帳號已鎖定密碼，請洽管理員';
    } else {
        $sth = $db->prepare("UPDATE user SET user_password = ? WHERE id = ?") or die("存入資料庫失敗");
        $sth->execute([$_POST["user_password"], $__targetId]);

        $msg = '更改成功！';
    }
}

if(isset($_POST['updataUserbtn'])){
    $cmd = $db->prepare("SELECT * FROM user where id =".$_GET['id']);
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['userid']=$row['id'];
    $_SESSION['user_cname']=$row['user_cname'];
    $_SESSION['user_uname']=$row['user_uname'];
    $_SESSION['user_password']=$row['user_password'];

    unset($_SESSION['user_uname']);
    unset($_SESSION['user_password']);
    unset($_SESSION['user_cname']);
};

if(isset($_POST['updataUser'])){
    $cmd = $db->prepare("SELECT * FROM user where id =".$_GET['userid']);
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['userid']=$row['id'];
    $_SESSION['user_cname']=$row['user_cname'];
    $_SESSION['user_uname']=$row['user_uname'];
    $_SESSION['user_password']=$row['user_password'];
};


// 清除
if (isset($_POST["resetUser"])) {

    unset($_SESSION['user_uname']);
    unset($_SESSION['user_password']);
    unset($_SESSION['user_cname']);
    
};