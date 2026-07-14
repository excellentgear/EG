<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

    $cmd = $db->prepare("SELECT * FROM news where id =".$_GET['userid']);
    $cmd->execute();
    $row = $cmd->fetch();

    $_SESSION['userid']     =$row['id'];
    $_SESSION['newsdate']   =$row['newsdate'];
    $_SESSION['title']      =$row['title'];
    $_SESSION['content']    =$row['content'];

header("location:../../views/news/news.php?userid=".$_GET['userid'].'&id='.$_GET['id']);