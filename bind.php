<?php
// bind.php - 乾淨的通知綁定專用頁面
session_start();
if (!isset($_SESSION['id'])) {
    // 尚未登入（iPhone 主畫面 App 常是獨立 session）：記住要回綁定頁，導去登入頁；
    // 登入成功後 Login.php 會依 lastpage 自動導回這裡。
    $_SESSION['lastpage'] = '/EGsystem/bind.php';
    header('Location: /EGsystem/index.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>LINE 通知替代方案 - 裝置綁定</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/EGsystem/manifest.json">
    <style>
        body { font-family: sans-serif; text-align: center; padding: 40px 20px; background: #f5f5f5; }
        .box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 400px; margin: 0 auto; }
        button { background: #007aff; color: white; border: none; padding: 15px 30px; font-size: 16px; font-weight: bold; border-radius: 8px; cursor: pointer; width: 100%; margin-top: 20px; }
        button:disabled { background: #ccc; }
        #log { text-align: left; background: #222; color: #0f0; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 13px; margin-top: 20px; white-space: pre-wrap; overflow-x: auto; }
    </style>
</head>
<body>

<div class="box">
    <h2>🔔 員工裝置通知綁定</h2>
    <p>目前登入使用者 ID: <strong><?php echo $_SESSION['id']; ?></strong></p>
    <p style="color: #666; font-size: 14px;">請點擊下方按鈕以啟用手機即時推播功能。</p>
    
    <button id="btn-bind">🔗 開始綁定手機通知</button>
    <div id="log">系統日誌：等待點擊...</div>
</div>

<script>
const btn = document.getElementById('btn-bind');
const logDiv = document.getElementById('log');

function log(msg) {
    logDiv.innerText += "\n> " + msg;
}

window.onload = () => {
    logDiv.innerText = "🔍 系統環境準備就緒，請點擊上方按鈕綁定。";
};

btn.addEventListener('click', async () => {
    log("--- 開始執行綁定程序 ---");
    btn.disabled = true;
    
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        log("💥 錯誤：核心 API 遺失，無法繼續。");
        btn.disabled = false;
        return;
    }
    
    try {
        log("1. 正在註冊背景服務 (Service Worker)...");
        const reg = await navigator.serviceWorker.register('/EGsystem/push-sw.js');
        
        log("2. 檢查手機通知權限...");
        if (Notification.permission === 'denied') {
            log("❌ 通知已被「封鎖」，系統不會再跳出詢問。");
            log("　解法：點網址列的鎖頭(或右上⋮)→ 網站設定 → 通知 → 改成「允許」，再重按綁定。");
            log("　並確認：Android 設定 → 應用程式 → Chrome → 通知 已開啟。");
            btn.disabled = false;
            return;
        }
        const permission = await Notification.requestPermission();
        if (permission === 'denied') {
            log("❌ 你剛剛選了「封鎖」。請到 網址列鎖頭 → 網站設定 → 通知 → 允許 後再試。");
            btn.disabled = false;
            return;
        }
        if (permission !== 'granted') {
            log("❌ 尚未允許通知（可能把跳窗關掉了）。請再按一次綁定，並選「允許」。");
            log("　若完全沒跳出詢問：請先到 Android 設定 → 應用程式 → Chrome → 通知 開啟。");
            btn.disabled = false;
            return;
        }
        log("✅ 已允許通知");
        
        log("3. 權限通過！正在讀取伺服器公鑰...");
        const keyRes = await fetch('/EGsystem/src/store/push_public_key.php');
        const keyData = await keyRes.json();
        
        log("4. 向 Apple 伺服器申請裝置加密憑證...");
        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyData.publicKey
        });
        
        log("5. 正在將憑證寫入 ERP 資料庫...");
        const subRes = await fetch('/EGsystem/src/store/push_subscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub)
        });
        
        const subResult = await subRes.json();
        log("🎉 綁定成功！資料庫回報：" + JSON.stringify(subResult));
        
    } catch (err) {
        log("💥 發生錯誤: " + err.message);
        btn.disabled = false;
    }
});
</script>

</body>
</html>