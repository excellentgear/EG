<?php
 if (!isset($_SESSION)){
    session_start();
    }

include('../../src/common/DBConnection.php');


    $conn = new DBConnection();

    $students = $conn->execute("DELETE FROM schedule WHERE id=".$_GET['s_id']);
    
    header("location:../../views/pages/schedule_TG.php?id=".$_GET['id']);
