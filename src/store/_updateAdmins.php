<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

    $cmd = $db->prepare("SELECT * FROM user where id =".$_GET['userid']);
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['userid']=$row['id'];
    $_SESSION['user_cname']=$row['user_cname'];
    $_SESSION['user_uname']=$row['user_uname'];
    $_SESSION['user_password']=$row['user_password'];
    $_SESSION['byear']=$row['byear'];
    $_SESSION['gender']=$row['gender'];
    $_SESSION['email']=$row['email'];
    $_SESSION['phone']=$row['phone'];
    $_SESSION['user_address']=$row['user_address'];
    $_SESSION['em_name']=$row['em_name'];
    $_SESSION['em_phone']=$row['em_phone'];

header("location:../../views/user/admins.php?userid=".$_GET['userid'].'&id='.$_GET['id']);

