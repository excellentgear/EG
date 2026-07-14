<?php
require_once __DIR__ . '/../../resource/phpqrcode/qrlib.php';

if (!isset($_GET['text'])) {
    // It's better to output a placeholder image or an error image
    // header("Content-type: image/png");
    // readfile("path/to/your/error_or_placeholder_qr.png"); // Example
    exit('缺少參數 (text parameter is missing)');
}

$text = $_GET['text'];
QRcode::png($text);