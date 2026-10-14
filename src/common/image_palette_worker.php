<?php
// 背景轉檔工作程序（CLI 專用）：php image_palette_worker.php <PNG 完整路徑>
// 由 image_palette_lib.php 的 eg_png_to_palette256_async() 派出；失敗一律保留原圖。
if (PHP_SAPI !== 'cli') { exit; }
require_once __DIR__ . '/image_palette_lib.php';
$f = $argv[1] ?? '';
// 只准處理料號附件目錄底下的 egdraw_*.png（避免被亂用來轉任意檔案）
if ($f === '' || !preg_match('~[\\\\/]egdraw_[A-Za-z0-9_]+\.png$~', $f)) { exit(1); }
$ok = eg_png_to_palette256($f);
@file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'egpng256_worker.log',
    date('Y-m-d H:i:s') . ' ' . ($ok ? 'OK ' : 'skip ') . $f . "\n", FILE_APPEND);
