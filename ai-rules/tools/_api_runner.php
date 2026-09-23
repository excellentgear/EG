<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['DOCUMENT_ROOT'] = 'C:/MAMP/htdocs';
$_SERVER['REQUEST_METHOD'] = 'POST';
session_start();
$_SESSION['id'] = (int)$argv[1];
$_SESSION['userName'] = 'test';
$req  = json_decode(base64_decode($argv[2]), true) ?: [];
$post = json_decode(base64_decode($argv[3]), true) ?: [];
$_GET = $req; $_POST = $post; $_REQUEST = array_merge($req, $post);
ob_start();
include 'C:/MAMP/htdocs/EGsystem/src/store/Leave_API.php';
$o = ob_get_clean();
echo $o;