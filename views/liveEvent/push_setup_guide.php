<?php
// 公告推播通知：自我診斷面板 + 手機/電腦設定說明（由 createEvent.php 的訂閱裝置 modal include）
// 注意：本片段 include 的位置在 jQuery 載入之前，因此本檔 JS 一律用原生 JS（不可用 $）。
?>
<style>
    #subsTabGuide { font-size: 13.5px; color: #333; line-height: 1.75; }
    #subsTabGuide h3 { font-size: 16.5px; font-weight: 700; margin: 26px 0 10px; padding-bottom: 6px; border-bottom: 2px solid #eee; color: #2c3e50; }
    #subsTabGuide h4 { font-size: 14.5px; font-weight: 700; margin: 18px 0 6px; color: #34495e; }
    #subsTabGuide ol, #subsTabGuide ul { padding-left: 22px; margin-bottom: 8px; }
    #subsTabGuide li { margin-bottom: 3px; }
    #subsTabGuide code { background: #f4f6f8; border: 1px solid #e3e7ea; border-radius: 3px; padding: 1px 6px; color: #c0392b; font-size: 12.5px; word-break: break-all; }
    .pg-diag { background: #f8fafc; border: 1px solid #dde5ec; border-radius: 8px; padding: 14px 16px; margin-bottom: 6px; }
    .pg-diag-title { font-size: 15px; font-weight: 700; color: #2c3e50; margin-bottom: 8px; }
    .pg-diag table { width: 100%; border-collapse: collapse; }
    .pg-diag td { padding: 5px 8px; border-bottom: 1px dashed #e5eaef; vertical-align: top; }
    .pg-diag td:first-child { width: 128px; color: #7f8c8d; white-space: nowrap; font-weight: 700; }
    .pg-diag tr:last-child td { border-bottom: none; }
    .pg-diag-btn { background: #fff; border: 1px solid #cfd8e0; border-radius: 14px; padding: 3px 14px; font-size: 12.5px; cursor: pointer; color: #34495e; }
    .pg-diag-btn:hover { background: #eef3f7; }
    .pg-qr-row { display: flex; gap: 26px; flex-wrap: wrap; margin: 14px 0 4px; }
    .pg-qr { text-align: center; }
    .pg-qr img { width: 150px; height: 150px; border: 1px solid #eee; border-radius: 6px; padding: 6px; background: #fff; }
    .pg-qr .pg-qr-cap { font-weight: 700; margin-top: 4px; }
    .pg-qr .pg-qr-url { font-size: 11.5px; color: #888; word-break: break-all; max-width: 170px; }
    .pg-callout { border-left: 4px solid #f39c12; background: #fef9ee; padding: 10px 14px; border-radius: 0 6px 6px 0; margin: 10px 0; }
    .pg-callout.pg-blue { border-color: #3498db; background: #eef6fc; }
    .pg-table { width: 100%; border-collapse: collapse; margin: 8px 0; }
    .pg-table th, .pg-table td { border: 1px solid #e3e7ea; padding: 7px 10px; text-align: left; vertical-align: top; }
    .pg-table th { background: #f4f6f8; white-space: nowrap; }
</style>

<!-- ===== 自我診斷面板 ===== -->
<div class="pg-diag">
    <div class="pg-diag-title" style="display:flex;justify-content:space-between;align-items:center;">
        <span>🩺 本裝置自我診斷</span>
        <button type="button" class="pg-diag-btn" onclick="egPushDiagRun()">↻ 重新檢測</button>
    </div>
    <table>
        <tr><td>連線協定</td><td id="pd-proto">－</td></tr>
        <tr><td>網址主機</td><td id="pd-host">－</td></tr>
        <tr><td>瀏覽器支援</td><td id="pd-support">－</td></tr>
        <tr><td>iPhone 開啟方式</td><td id="pd-ios">－</td></tr>
        <tr><td>通知權限</td><td id="pd-perm">－</td></tr>
        <tr><td>本裝置訂閱</td><td id="pd-sub">－</td></tr>
        <?php if (isset($tg_hb_stale)) : ?>
        <tr><td>Telegram 輪詢</td><td style="color:<?= $tg_hb_stale ? '#c0392b' : '#27ae60' ?>;"><?= $tg_hb_stale ? '❌ 心跳超過 5 分鐘未更新（按鈕回覆/附件索取暫時無反應，瀏覽系統頁面會自動重啟）' : '✅ 服務正常' ?></td></tr>
        <?php endif; ?>
    </table>
    <div style="font-size:12px;color:#95a5a6;margin-top:6px;">在要收通知的那台裝置上開啟本頁檢測才準確；紅色 ❌ 項目請對照下方說明處理。</div>
</div>

<!-- ===== 快速入口 QR ===== -->
<div class="pg-qr-row">
    <div class="pg-qr">
        <img src="../../resource/images/qr_egsystem_site.png" alt="網站 QR">
        <div class="pg-qr-cap">網站</div>
        <div class="pg-qr-url">https://192.168.2.128/EGsystem</div>
    </div>
    <div class="pg-qr">
        <img src="../../resource/images/qr_egsystem_cert.png" alt="憑證下載 QR">
        <div class="pg-qr-cap">憑證下載</div>
        <div class="pg-qr-url">http://192.168.2.128/EGsystem/egsystem-ca.crt</div>
    </div>
    <div style="align-self:center;font-size:12.5px;color:#7f8c8d;max-width:320px;">用手機相機掃描即可開啟對應網址（需連公司 Wi-Fi）。</div>
</div>

<!-- ===== 設定說明 ===== -->
<h3 style="margin-top:18px;">EGsystem 公告推播通知　設定說明</h3>
<p>讓你在<b>關閉網頁、甚至關閉瀏覽器</b>後，電腦或手機仍能收到公司公告 / 通知。</p>

<h4>📌 開始前必看（三個重點）</h4>
<ol>
    <li><b>一定要用這個網址</b>（有 <code>https</code>、有 <code>s</code>）：<br>
        <code>https://192.168.2.128/EGsystem</code><br>
        （用 <code>http://</code>、或用 localhost 都收不到通知）</li>
    <li><b>要先登入系統</b>：通知會綁定你的登入帳號，系統才知道要推給誰。</li>
    <li><b>收通知 vs 看內容，網路需求不同</b>：
        <ul>
            <li><b>收到通知橫幅</b>：只要手機有網路（<b>公司 Wi-Fi 或 4G 都可以</b>），因為通知是從 Apple / Google 雲端送到你手機。</li>
            <li><b>點通知打開公告內容</b>：頁面在公司內網，需連<b>公司 Wi-Fi 或 VPN</b> 才打得開（4G 看得到通知、但點進去要在公司網路）。</li>
        </ul>
    </li>
</ol>

<h3>🔐 第一步（所有裝置共通）：安裝信任憑證</h3>
<p>因為是公司內部網站，要先讓你的裝置「信任」它，之後才不會出現「不安全」警告、通知也才會生效。</p>
<p><b>憑證下載網址</b>（用瀏覽器開，或掃上方「憑證下載」QR）：<br>
<code>http://192.168.2.128/EGsystem/egsystem-ca.crt</code></p>
<div class="pg-callout pg-blue">檔名：<code>egsystem-ca.crt</code>。下載後依你的裝置照下面步驟安裝。</div>

<h3>💻 一、電腦（Windows　Chrome / Edge）</h3>
<h4>A. 安裝憑證</h4>
<ol>
    <li>下載 <code>egsystem-ca.crt</code>（上面的網址）。</li>
    <li><b>雙擊</b>該檔 → 按「<b>安裝憑證</b>」。</li>
    <li>存放位置選「<b>本機電腦</b>」→ 下一步。</li>
    <li>選「<b>將所有憑證放入以下的存放區</b>」→「瀏覽」→ 選「<b>受信任的根憑證授權單位</b>」→ 確定 → 完成。</li>
    <li><b>完全關閉瀏覽器再重開</b>。</li>
</ol>
<h4>B. 開啟通知</h4>
<ol>
    <li>開 <code>https://192.168.2.128/EGsystem</code> 並<b>登入</b>（網址列應顯示鎖頭、無警告）。</li>
    <li>進「公告 / 通知」頁，點右上角「<b>🔔 開啟通知</b>」。</li>
    <li>瀏覽器跳出詢問 → 按「<b>允許</b>」。按鈕變「通知已開啟」即完成。</li>
</ol>
<div class="pg-callout">沒反應時：點網址列左邊鎖頭 →「網站設定」→ 把「通知」改成「允許」，重新整理再點一次。</div>

<h3>📱 二、iPhone / iPad（限 iOS 16.4 以上，須用 Safari）</h3>
<div class="pg-callout">⚠️ iPhone 有兩個硬性規定：<b>必須用 Safari</b>、而且<b>必須「加入主畫面」當 App 開</b>，否則不會有通知功能。</div>
<h4>A. 安裝並「信任」憑證（這步 iPhone 最容易漏）</h4>
<ol>
    <li>用 <b>Safari</b> 開 <code>http://192.168.2.128/EGsystem/egsystem-ca.crt</code> → 允許下載描述檔。</li>
    <li>設定 →「<b>一般</b>」→「<b>VPN 與裝置管理</b>」→ 點「<b>EG System Internal Root CA</b>」→ 右上「<b>安裝</b>」。</li>
    <li>設定 →「一般」→「<b>關於本機</b>」→ 拉到最下面「<b>憑證信任設定</b>」→ 把「<b>EG System Internal Root CA</b>」的開關<b>打開</b>。</li>
</ol>
<h4>B. 登入並把「綁定頁」加入主畫面</h4>
<ol start="4">
    <li>用 <b>Safari</b> 開 <code>https://192.168.2.128/EGsystem</code> 並<b>登入你的帳號</b>（確認網址列沒有憑證警告）。</li>
    <li>登入後，把網址改開<b>綁定專用頁</b>：<br>
        <code>https://192.168.2.128/EGsystem/bind.php</code><br>
        頁面會顯示「目前登入使用者 ID: ⬛⬛⬛」，代表已登入成功。</li>
    <li>在這個綁定頁上，點下方「<b>分享</b>」圖示 → 「<b>加入主畫面</b>」→ 加入。</li>
</ol>
<h4>C. 從主畫面 App 做綁定</h4>
<ol start="7">
    <li>從<b>主畫面的圖示</b>開啟本 App（一定要這樣開，Safari 分頁裡不會有通知功能，會自動開到綁定頁 <code>bind.php</code>）。
        <ul><li>※ 若 App 開起來跳到登入畫面：直接在 App 裡登入，登入後會<b>自動回到綁定頁</b>。</li></ul></li>
    <li>點藍色大按鈕「<b>🔗 開始綁定手機通知</b>」→ 系統詢問時按「<b>允許</b>」。</li>
    <li>看到「<b>🎉 綁定成功</b>」即完成。</li>
</ol>
<div class="pg-callout pg-blue">
    💡 <b>請保留主畫面的 App 圖示</b>，刪掉＝取消通知。<br>
    💡 iPhone 綁定一律在 bind.php 這頁按「開始綁定手機通知」，不是用公告頁的按鈕。
</div>

<h3>🤖 三、Android 手機（Chrome）</h3>
<h4>A. 安裝憑證</h4>
<ol>
    <li>用 Chrome 開 <code>http://192.168.2.128/EGsystem/egsystem-ca.crt</code> 下載。</li>
    <li>設定 → 搜尋「<b>憑證</b>」→「<b>安裝憑證 / 從儲存空間安裝</b>」→ 選「<b>CA 憑證</b>」→ 選剛下載的 <code>egsystem-ca.crt</code>。<br>
        （各廠牌路徑略有不同，多在「設定 → 安全性 → 更多安全性設定 → 加密與憑證 → 安裝憑證」）</li>
</ol>
<h4>B. 開啟通知</h4>
<ol start="3">
    <li>用 Chrome 開 <code>https://192.168.2.128/EGsystem</code> 並<b>登入</b>（應顯示鎖頭、無警告）。</li>
    <li>（建議）右上「⋮」→「<b>加到主畫面</b>」，日後從主畫面開更穩定。</li>
    <li>進「公告 / 通知」頁，點右上「<b>🔔 開啟通知</b>」→「允許」。</li>
</ol>

<h3>✅ 測試是否成功</h3>
<p>設定完成後，請系統管理員<b>發布一則測試公告</b>（對象包含你），確認你的裝置有跳出通知。<br>
收到＝設定成功；收不到請看下方排除。</p>

<h3>🛠 常見問題排除</h3>
<table class="pg-table">
    <thead><tr><th>狀況</th><th>處理方式</th></tr></thead>
    <tbody>
        <tr><td>網址列顯示「不安全」</td><td>憑證沒裝好。電腦要裝到「<b>本機電腦 → 受信任的根憑證授權單位</b>」；iPhone 要記得開「<b>憑證信任設定</b>」開關</td></tr>
        <tr><td>iPhone 在綁定頁沒有「開始綁定」可按 / 按了沒反應</td><td>① iOS 需 16.4 以上　② 一定要從<b>主畫面 App 圖示</b>開 <code>bind.php</code>，不能在 Safari 分頁開</td></tr>
        <tr><td>iPhone App 一開就顯示登入畫面</td><td>正常，App 是獨立登入。直接在 App 裡登入，會自動回到綁定頁</td></tr>
        <tr><td>找不到「憑證信任設定」</td><td>表示 iPhone 的描述檔還沒安裝成功，回到「設定 → 一般 → VPN 與裝置管理」重裝一次</td></tr>
        <tr><td>按了允許卻沒收到</td><td>確認 ① 用的是 <code>https://192.168.2.128</code>　② 已登入　③ 手機的系統通知權限有開給瀏覽器 / 本 App</td></tr>
        <tr><td>出差用 4G 收到通知但點不開</td><td>正常。通知內容在公司內網，需連公司 Wi-Fi 或 VPN 才打得開頁面</td></tr>
        <tr><td>換手機 / 換帳號</td><td>在新裝置 / 新帳號重做一次「開啟通知」即可，系統會自動改綁</td></tr>
    </tbody>
</table>

<p style="color:#7f8c8d;font-size:12.5px;margin-top:14px;">隱私說明：通知內容經<b>端對端加密</b>傳送，Apple / Google 只負責轉送、看不到內容。</p>

<script>
// 自我診斷（原生 JS：本片段在 jQuery 載入前 include，不可用 $）
function egPushDiagRun() {
    var set = function(id, ok, txt) {
        var el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = (ok === true ? '✅ ' : ok === false ? '❌ ' : '⚠️ ') + txt;
        el.style.color = ok === true ? '#27ae60' : ok === false ? '#c0392b' : '#e67e22';
    };
    // 1. 連線協定
    var https = location.protocol === 'https:';
    set('pd-proto', https, https ? 'https 加密連線（正確）' : '目前用 http 開啟 — 收不到通知，請改用 https://192.168.2.128/EGsystem');
    // 2. 網址主機
    var host = location.hostname, hostOk = (host === '192.168.2.128');
    set('pd-host', hostOk ? true : undefined, '目前：' + host + (hostOk ? '（正確）' : '（建議用 192.168.2.128；localhost 或其他位址可能收不到通知）'));
    // 3. 瀏覽器支援
    var sup = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    if (sup) set('pd-support', true, '瀏覽器支援推播');
    else if (isIOS && !standalone) set('pd-support', false, '目前不支援 — iPhone 需 iOS 16.4+，且要「加入主畫面」後從主畫面圖示開啟（見下方說明二）');
    else set('pd-support', false, '此瀏覽器不支援推播（請改用 Chrome / Edge / Safari 16.4+）');
    // 4. iPhone 開啟方式
    if (isIOS) set('pd-ios', standalone ? true : false, standalone ? '已從主畫面 App 開啟（正確）' : '目前在 Safari 分頁中 — 必須「加入主畫面」並從主畫面圖示開啟才有通知功能');
    else set('pd-ios', true, '非 iOS 裝置，不需要此步驟');
    // 5. 通知權限
    if ('Notification' in window) {
        var p = Notification.permission;
        set('pd-perm', p === 'granted' ? true : (p === 'denied' ? false : undefined),
            p === 'granted' ? '已允許通知' : (p === 'denied' ? '已封鎖 — 請到瀏覽器網站設定把「通知」改為允許後重新整理' : '尚未詢問 — 請點公告頁右上「🔔 開啟通知」'));
    } else set('pd-perm', false, '瀏覽器不支援通知');
    // 6. 本裝置訂閱狀態
    var subEl = document.getElementById('pd-sub');
    if (subEl) {
        subEl.innerHTML = '⏳ 檢查中…';
        subEl.style.color = '#888';
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistration()
                .then(function(reg) { return reg ? reg.pushManager.getSubscription() : null; })
                .then(function(s) { set('pd-sub', !!s, s ? '本裝置已訂閱推播' : '尚未訂閱 — 請點公告頁右上「🔔 開啟通知」完成訂閱'); })
                .catch(function() { set('pd-sub', undefined, '無法確認訂閱狀態'); });
        } else set('pd-sub', false, '瀏覽器不支援');
    }
}
</script>
