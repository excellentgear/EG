<?php
 if (!isset($_SESSION)){
    session_start();
    }

    unset($_SESSION['eventid']);
    unset($_SESSION['eventdate']);
    unset($_SESSION['enddate']);
    unset($_SESSION['title']);
    unset($_SESSION['content']);
    unset($_SESSION['eventstatus']);

    header("location:../../views/liveEvent/createEvent.php");
?>