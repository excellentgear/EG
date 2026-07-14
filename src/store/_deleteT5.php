<?php
 if (!isset($_SESSION)){
    session_start();
    }

include('../../src/common/DBConnection.php');


    $conn = new DBConnection();

    $students = $conn->execute("DELETE FROM schedule WHERE id=".$_GET['s_id']);
    
    header("location:../../views/pm/schedule_T5.php?id=".$_GET['id']);
