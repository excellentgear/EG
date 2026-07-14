<?php
 if (!isset($_SESSION)){
    session_start();
    };
    include("../common/_config.php");

    

        header("location:../../views/pages/stock.php?d=".$_GET['d']."");

          