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

header("location:../../views/user/public.php?userid=".$_GET['userid'].'&id='.$_GET['id']);
