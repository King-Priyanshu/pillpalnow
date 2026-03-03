importScripts("https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js");

// Custom notification handling with action buttons
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    // Track click analytics
    trackNotificationEvent('click', event.notification.data);
    // Allow OneSignal default handling
});

self.addEventListener('notificationaction', function (event) {
    event.notification.close();
    const action = event.action;
    // Handle custom actions
    handleNotificationAction(action, event.notification.data);
    // Track action analytics
    trackNotificationEvent('action', event.notification.data, action);
});

// Function to handle notification actions
function handleNotificationAction(action, data) {
    switch (action) {
        case 'mark_as_taken':
            // Logic to mark medication as taken
            sendActionToServer('taken', data);
            break;
        case 'skip':
            // Logic to skip the dose
            sendActionToServer('skipped', data);
            break;
        case 'postpone':
            // Logic to postpone the notification
            sendActionToServer('postponed', data);
            break;
        default:
            // Default handling
            break;
    }
}

// Function to track notification events
function trackNotificationEvent(eventType, data, action = null) {
    const payload = {
        event: eventType,
        notificationId: data ? data.id : null,
        action: action,
        timestamp: Date.now()
    };
    // Send to analytics endpoint
    fetch('/wp-json/pillpalnow/v1/analytics/notification', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    }).catch(function (error) {
        // If offline, queue for later
        queueNotificationEvent(payload);
    });
}

// Function to send action to server
function sendActionToServer(actionType, data) {
    fetch('/wp-json/pillpalnow/v1/medication/action', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: actionType,
            medicationId: data ? data.medicationId : null,
            timestamp: Date.now()
        })
    }).catch(function (error) {
        // Queue if offline
        queueAction(actionType, data);
    });
}

// Background sync for offline queuing
self.addEventListener('sync', function (event) {
    if (event.tag === 'notification-queue') {
        event.waitUntil(processQueuedNotifications());
    }
});

// Function to queue notification events
function queueNotificationEvent(payload) {
    caches.open('notification-queue').then(function (cache) {
        const request = new Request('/queued-notification-' + Date.now(), { method: 'POST', body: JSON.stringify(payload) });
        cache.put(request, new Response(JSON.stringify(payload)));
    });
}

// Function to queue actions
function queueAction(actionType, data) {
    const payload = {
        action: actionType,
        medicationId: data ? data.medicationId : null,
        timestamp: Date.now()
    };
    caches.open('action-queue').then(function (cache) {
        const request = new Request('/queued-action-' + Date.now(), { method: 'POST', body: JSON.stringify(payload) });
        cache.put(request, new Response(JSON.stringify(payload)));
    });
    // Register sync
    self.registration.sync.register('notification-queue');
}

// Function to process queued notifications
function processQueuedNotifications() {
    return caches.open('notification-queue').then(function (cache) {
        return cache.keys().then(function (requests) {
            return Promise.all(requests.map(function (request) {
                return cache.match(request).then(function (response) {
                    return response.json().then(function (payload) {
                        return fetch('/wp-json/pillpalnow/v1/analytics/notification', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        }).then(function () {
                            return cache.delete(request);
                        });
                    });
                });
            }));
        });
    }).then(function () {
        // Also process action queue
        return caches.open('action-queue').then(function (cache) {
            return cache.keys().then(function (requests) {
                return Promise.all(requests.map(function (request) {
                    return cache.match(request).then(function (response) {
                        return response.json().then(function (payload) {
                            return fetch('/wp-json/pillpalnow/v1/medication/action', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(payload)
                            }).then(function () {
                                return cache.delete(request);
                            });
                        });
                    });
                }));
            });
        });
    });
}