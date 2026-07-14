<?php
// 提供前端 VAPID 公開金鑰（subscribe() 需要）。公開金鑰可外流，無安全疑慮。
//header('Content-Type: application/json; charset=utf-8');
//$cfg = require __DIR__ . '/../push/push_config.php';
//echo json_encode(['ok' => true, 'publicKey' => $cfg['publicKey'] ?? '']);

// src/store/push_public_key.php
header('Content-Type: application/json');

// 動態引入正確的設定檔 (從 src/store 回退一層到 src，再進入 push)
require_once __DIR__ . '/../push/push_config.php';

// 動態輸出給 bind.php 使用
echo json_encode(['publicKey' => VAPID_PUBLIC_KEY]);
?>