/* EGsystem Web Push 前端用戶端
 * 流程：詢問權限 → 註冊 Service Worker → 取得/建立 Subscription → 連同登入者 ID 送回後端。
 *
 * 用法（頁面已登入時）：
 *   <script>window.EG_PUSH_BASE = '/EGsystem';</script>
 *   <script src="/EGsystem/resource/js/push-client.js"></script>
 *   然後在「允許通知」按鈕的 click 呼叫  EGPush.enable();
 *   頁面載入時會自動 EGPush.refresh()（若先前已授權，確保訂閱仍存在且已綁定目前帳號）。
 */
(function (global) {
    'use strict';

    var BASE = global.EG_PUSH_BASE || '/EGsystem';
    var SW_URL = BASE + '/push-sw.js';
    var SW_SCOPE = BASE + '/';
    var API_PUBKEY = BASE + '/src/store/push_public_key.php';
    var API_SUB = BASE + '/src/store/push_subscribe.php';
    var API_UNSUB = BASE + '/src/store/push_unsubscribe.php';

    function supported() {
        return ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
    }

    function isSecure() {
        // Service Worker 僅在安全來源(https)或 localhost 可用
        return window.isSecureContext === true;
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var arr = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    function getJSON(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function postJSON(url, obj) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(obj)
        }).then(function (r) { return r.json(); });
    }

    function registerSW() {
        return navigator.serviceWorker.register(SW_URL, { scope: SW_SCOPE });
    }

    // 取得（或建立）訂閱，並送回後端。mode='bind' 搶佔綁定；'refresh' 只同步不搶佔
    function subscribeAndSync(reg, mode) {
        return getJSON(API_PUBKEY).then(function (res) {
            if (!res || !res.publicKey) throw new Error('無法取得 VAPID 公開金鑰');
            return reg.pushManager.getSubscription().then(function (existing) {
                if (existing) return existing;
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(res.publicKey)
                });
            });
        }).then(function (sub) {
            var json = sub.toJSON();
            return postJSON(API_SUB, {
                endpoint: sub.endpoint,
                keys: json.keys,
                mode: mode || 'bind'   // bind＝主動綁定(搶佔) / refresh＝載入時同步(不搶佔)
            }).then(function (r) {
                if (!r || !r.ok) throw new Error(r && r.msg ? r.msg : '訂閱儲存失敗');
                return sub;
            });
        });
    }

    var EGPush = {
        isSupported: supported,

        // 使用者主動點「允許通知」時呼叫
        enable: function () {
            if (!supported()) { alert('此瀏覽器不支援網頁推播通知。'); return Promise.resolve(false); }
            if (!isSecure()) {
                alert('網頁推播需要安全連線(HTTPS)。目前為非安全連線，請改用 https 網址後再開啟通知。');
                return Promise.resolve(false);
            }
            return Notification.requestPermission().then(function (perm) {
                if (perm !== 'granted') {
                    alert('您未允許通知。若要接收公告推播，請於瀏覽器網站設定開啟「通知」權限。');
                    return false;
                }
                return registerSW().then(function (reg) { return subscribeAndSync(reg, 'bind'); }).then(function () {
                    return true;
                });
            }).catch(function (err) {
                console.error('[push] enable failed:', err);
                alert('開啟通知失敗：' + (err && err.message ? err.message : err));
                return false;
            });
        },

        // 頁面載入時靜默同步（僅在先前已授權時），確保訂閱存在並綁定目前帳號
        refresh: function () {
            if (!supported() || !isSecure()) return Promise.resolve(false);
            if (Notification.permission !== 'granted') return Promise.resolve(false);
            return registerSW().then(function (reg) { return subscribeAndSync(reg, 'refresh'); }).then(function () {
                return true;
            }).catch(function (err) {
                console.warn('[push] refresh failed:', err);
                return false;
            });
        },

        // 關閉此裝置的通知
        disable: function () {
            if (!supported()) return Promise.resolve(false);
            return navigator.serviceWorker.getRegistration(SW_SCOPE).then(function (reg) {
                if (!reg) return false;
                return reg.pushManager.getSubscription().then(function (sub) {
                    if (!sub) return false;
                    var endpoint = sub.endpoint;
                    return sub.unsubscribe().then(function () {
                        return postJSON(API_UNSUB, { endpoint: endpoint });
                    }).then(function () { return true; });
                });
            });
        },

        // 目前狀態：'unsupported' | 'insecure' | 'default' | 'granted' | 'denied'
        status: function () {
            if (!supported()) return 'unsupported';
            if (!isSecure()) return 'insecure';
            return Notification.permission; // default / granted / denied
        }
    };

    global.EGPush = EGPush;

    // 已授權者：頁面載入後自動同步一次
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(function () { EGPush.refresh(); }, 0);
    } else {
        window.addEventListener('load', function () { EGPush.refresh(); });
    }
})(window);
