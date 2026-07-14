<?php
 if (!isset($_SESSION)){
    session_start();
    }

    unset($_SESSION['pn']);

    header("location:../../views/pm/OreadyReply_ForPm.php");
?>