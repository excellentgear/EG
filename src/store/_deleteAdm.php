<?php
 if (!isset($_SESSION)){
    session_start();
    }

include('../../src/common/DBConnection.php');


    $conn = new DBConnection();

    $adm = $conn->execute("DELETE FROM user WHERE id=".$_GET['userid']);
    
    header("location:../../views/user/adm.php?id=".$_GET['id']);
