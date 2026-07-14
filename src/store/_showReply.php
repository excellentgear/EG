<?php
 if (!isset($_SESSION)){
    session_start();
    };
    include("../common/_config.php");

    

        header("location:../../views/reply/reply_other.php?pti=".$_GET['pti']."&ri=".$_GET['ri']."&bi=".$_GET['bi']."&BOM=".$_GET['BOM']."&d=".$_GET['d']."&pna=".$_GET['pna']."&ProcessNo=".$_GET['pn']."&MakerId=".$_GET['mi']."&sqty=".$_GET['s']."&C=".$_GET['C']."&rd=".$_GET['rd']."");

          