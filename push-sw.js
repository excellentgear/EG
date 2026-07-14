/* EGsystem Web Push Service Worker
 * 職責：
 *   1. 在背景（即使網頁/瀏覽器已關閉）監聽 push 事件，解析後端傳來的 JSON，彈出系統通知。
 *   2. 處理 notificationclick：點擊通知後聚焦既有分頁或開啟指定網址。
 * 置於 /EGsystem/push-sw.js，scope 涵蓋整個 /EGsystem/ 應用。
 */

self.addEventListener('install', function (event) {
    // 立即啟用新版 SW
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: '新通知', body: event.data ? event.data.text() : '' };
    }

    var title = data.title || '公告 / 通知';
    var options = {
        body: data.body || '',
        icon: data.icon || '/EGsystem/resource/images/icon.png',
        badge: data.badge || '/EGsystem/resource/images/icon.png',
        tag: data.tag || undefined,          // 同 tag 會覆蓋前一則，避免洗版
        renotify: !!data.tag,
        requireInteraction: false,
        data: {
            url: data.url || '/EGsystem/views/liveEvent/mobile.php',
            eventId: data.eventId || null
        }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// 同源驗證：通知的開啟網址只允許本站（相對路徑或同源絕對網址），
// 其他一律退回手機公告頁，防止惡意/異常 payload 把使用者導去外部網站。
var SW_FALLBACK_URL = '/EGsystem/views/liveEvent/mobile.php';
function swSafeUrl(u) {
    if (!u) return SW_FALLBACK_URL;
    try {
        var url = new URL(u, self.location.origin);
        if (url.origin === self.location.origin) return url.pathname + url.search + url.hash;
    } catch (e) {}
    return SW_FALLBACK_URL;
}

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var targetUrl = swSafeUrl(event.notification.data && event.notification.data.url);

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            // 已有開啟中的分頁 → 聚焦並導向目標頁
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if ('focus' in client) {
                    client.focus();
                    if ('navigate' in client) {
                        try { client.navigate(targetUrl); } catch (e) {}
                    }
                    return;
                }
            }
            // 沒有開啟中的分頁 → 開新視窗
            if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
        })
    );
});
