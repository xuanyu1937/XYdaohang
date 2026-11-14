/* 轩宇-导航 PWA Service Worker
 * 策略：页面导航网络优先（离线回退）；静态资源缓存优先（stale-while-revalidate 简化版）。
 * 版本号更新时会清理旧缓存。
 * 发版时必须把 CACHE 里的版本串与 VERSION / config/version.php 同步（v2.18.4 修订）。
 */
var CACHE = 'xy-nav-pwa-v2.18.6';
var OFFLINE_URL = 'offline.html';

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE).then(function (cache) {
            return cache.addAll([OFFLINE_URL, 'assets/img/logo.svg']);
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== CACHE; })
                .map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;

    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    // 后台与接口永不缓存
    if (/\/(admin\/|api\/|logout\.php|cron\/)/.test(url.pathname)) return;

    // 页面导航：网络优先，失败回退离线页
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
        e.respondWith(
            fetch(req).then(function (res) {
                var copy = res.clone();
                caches.open(CACHE).then(function (c) { c.put(req, copy); });
                return res;
            }).catch(function () {
                return caches.match(req).then(function (hit) {
                    return hit || caches.match(OFFLINE_URL);
                });
            })
        );
        return;
    }

    // 静态资源：缓存优先
    if (/\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf)$/i.test(url.pathname)) {
        e.respondWith(
            caches.match(req).then(function (hit) {
                if (hit) return hit;
                return fetch(req).then(function (res) {
                    if (res.ok) {
                        var copy = res.clone();
                        caches.open(CACHE).then(function (c) { c.put(req, copy); });
                    }
                    return res;
                });
            })
        );
    }
});
