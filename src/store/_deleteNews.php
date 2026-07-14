<?php
 if (!isset($_SESSION)){
    session_start();
    }

include('../../src/common/DBConnection.php');

    $conn = new DBConnection();

$subjects = $conn->execute("DELETE FROM news WHERE id=".$_GET['userid']);

header("location:../../views/news/news.php?id=".$_GET['id']);
