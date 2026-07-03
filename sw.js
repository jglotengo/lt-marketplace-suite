/**
 * LT Marketplace Suite - Service Worker
 * PWA Service Worker para el panel del vendedor
 * Version: 1.5.0
 */

const CACHE_NAME   = 'ltms-v1.5.0';
const STATIC_CACHE = 'ltms-static-v1.5.0';

// Recursos a pre-cachear
const STATIC_ASSETS = [
    '/ltms-dashboard/',
];

// ── Instalación ───────────────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch(() => {
                // Ignorar errores de pre-cache
            });
        })
    );
    self.skipWaiting();
});

// ── Activación ────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME && name !== STATIC_CACHE)
                    .map((name) => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});

// ── Fetch Strategy: Network First con fallback a cache ────────
self.addEventListener('fetch', (event) => {
    // Solo interceptar GET requests
    if (event.request.method !== 'GET') return;

    // No interceptar peticiones AJAX/WP
    const url = new URL(event.request.url);
    if (url.pathname.includes('admin-ajax.php') ||
        url.pathname.includes('wp-json') ||
        url.pathname.includes('wp-admin')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Cachear respuesta exitosa
                if (response.ok && !url.pathname.includes('?')) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                // Network falló: intentar desde cache
                return caches.match(event.request);
            })
    );
});

// ── Push Notifications ────────────────────────────────────────
self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};

    const options = {
        body:    data.message || 'Tienes una nueva notificación',
        icon:    data.icon || '/wp-content/plugins/lt-marketplace-suite/assets/img/icon-192x192.png',
        badge:   '/wp-content/plugins/lt-marketplace-suite/assets/img/icon-72x72.png',
        tag:     data.tag || 'ltms-notification',
        data:    { url: data.url || '/ltms-dashboard/' },
        actions: [
            { action: 'view', title: 'Ver' },
            { action: 'close', title: 'Cerrar' },
        ],
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'LT Marketplace', options)
    );
});

// ── Notification Click ────────────────────────────────────────
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'close') return;

    const url = event.notification.data?.url || '/ltms-dashboard/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            // Si ya hay una ventana abierta, enfocarla
            for (const client of windowClients) {
                if (client.url.includes('ltms-dashboard') && 'focus' in client) {
                    return client.focus();
                }
            }
            // Si no, abrir una nueva
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
