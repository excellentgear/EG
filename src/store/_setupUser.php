<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

// 自行修改user
if (isset($_POST["updataUserbtn"])  && $_POST["user_password"] != "") {

    $sth = $db->prepare("UPDATE user SET user_password='$_POST[user_password]' WHERE id =$_GET[id]") or die("存入資料庫失敗");
    $sth->execute();

    $msg = '更改成功！';
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