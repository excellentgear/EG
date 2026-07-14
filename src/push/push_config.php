<?php
// Web Push（PWA 推播）設定：VAPID 金鑰與寄件者識別。
//
// ⚠️ 金鑰為本系統專屬，請勿外流；更換金鑰會使所有既有訂閱失效（使用者需重新允許通知）。
// 重新產生金鑰（需要時）：於命令列執行
//   php -r "require 'vendor/autoload.php'; print_r(Minishlink\WebPush\VAPID::createVapidKeys());"
// 取得新的 publicKey / privateKey 後，更新下方兩行。
//
// 本檔同時提供兩種讀取方式，兩套程式都能用：
//   (1) 常數：VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT（push_public_key.php、push_debug.php 用）
//   (2) 陣列：require 本檔取得 ['subject','publicKey','privateKey']（push_send.php 用）

if (!defined('VAPID_PUBLIC_KEY'))  define('VAPID_PUBLIC_KEY',  'BLIJAkOri3d8m4fbjj8RHX0UIpNmyhYyf-Awlniu92MS91vdYWhvf_rK8RSH4dR6sfZ4Rndt0gxma8PONYsz8jw');
if (!defined('VAPID_PRIVATE_KEY')) define('VAPID_PRIVATE_KEY', 'zbBjkTOLKvnj8OwDcNcKuD7dgYRAGzGxKlz4kvU6pA8');
// ⚠️ Apple(APNs) 會驗證 sub：不可用 .local 等無效網域的 mailto（會回 403 BadJwtToken）。
// 用系統自身的 https 網址最保險；也可改為真實可路由的 email，如 mailto:someone@gmail.com。
if (!defined('VAPID_SUBJECT'))     define('VAPID_SUBJECT',     'https://192.168.2.128');

return [
    'subject'    => VAPID_SUBJECT,
    'publicKey'  => VAPID_PUBLIC_KEY,
    'privateKey' => VAPID_PRIVATE_KEY,
];
