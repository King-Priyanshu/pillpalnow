/**
 * PillPalNow OneSignal Integration
 * Handles initialization, subscription, and permission management.
 */

(function ($) {
    'use strict';

    // Ensure configuration exists
    if (typeof pillpalnowOneSignal === 'undefined') {
        console.error('PillPalNow OneSignal configuration missing.');
        return;
    }

    const config = pillpalnowOneSignal;

    // Inject Modal HTML if not present
    function injectBlockedModal() {
        if (document.getElementById('onesignal-blocked-modal')) {
            return;
        }

        const modalHtml = `
            <div id="onesignal-blocked-modal" class="hidden" style="display:none;">
                <div class="os-modal-backdrop" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
                    <div class="os-modal-content" style="background:white;padding:30px;border-radius:12px;max-width:400px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.15);position:relative;">
                        <button class="os-modal-close" style="position:absolute;top:10px;right:15px;font-size:20px;cursor:pointer;color:#999;background:none;border:none;">&times;</button>
                        <div class="os-modal-icon" style="font-size:40px;margin-bottom:15px;">⚠️</div>
                        <div class="os-modal-title" style="font-size:20px;font-weight:bold;margin-bottom:10px;color:#333;">Notifications Blocked</div>
                        <div class="os-modal-text" style="font-size:14px;color:#666;margin-bottom:20px;line-height:1.5;">
                            We strongly recommend enabling notifications to get important medication reminders.
                            <br><br>
                            Please click the <strong>Lock icon</strong> in your browser address bar and set Notifications to <strong>Allow</strong>.
                        </div>
                        <button class="os-modal-btn" style="background:#e54b4b;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;">I Understand</button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Bind events for the new modal
        const modal = document.getElementById('onesignal-blocked-modal');
        const backdrop = modal.querySelector('.os-modal-backdrop');
        const closeBtn = modal.querySelector('.os-modal-close');
        const actionBtn = modal.querySelector('.os-modal-btn');

        const closeModal = () => {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        };

        closeBtn.addEventListener('click', closeModal);
        actionBtn.addEventListener('click', closeModal);
        // backdrop.addEventListener('click', closeModal); // Optional: close on backdrop click
    }

    function showBlockedModal() {
        const modal = document.getElementById('onesignal-blocked-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'block';
        }
    }

    // Initialize OneSignal
    window.OneSignalDeferred = window.OneSignalDeferred || [];

    // Load SDK if not already loaded (though it should be via enqueue or CDN, let's play safe or rely on header/plugin loading)
    // For now, we assume the OneSignal SDK script tag is added via wp_enqueue_scripts or similar.
    // Since we didn't add the SDK script tag in the PHP edit, we should dynamically load it here or add it in PHP.
    // The previous implementation had it in header.php.
    // Let's dynamically load it to be self-contained.
    if (!document.getElementById('onesignal-sdk')) {
        const script = document.createElement('script');
        script.id = 'onesignal-sdk';
        script.src = 'https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js';
        script.defer = true;
        document.head.appendChild(script);
    }

    OneSignalDeferred.push(async function (OneSignal) {
        console.log('[PillPalNow] OneSignal SDK loaded, initializing...');

        try {
            await OneSignal.init({
                appId: config.appId,
                serviceWorkerPath: "OneSignalSDKWorker.js", // Force strict relative filename
                serviceWorkerParam: { scope: "/" }, // Force root scope explicitly
                path: "/", // Suppress OneSignal's automatic subfolder URL calculation
                notifyButton: {
                    enable: false
                },
                promptOptions: {
                    slidedown: {
                        enabled: true,
                        autoPrompt: true,
                        timeDelay: 5,
                        pageViews: 1
                    }
                }
            });

            console.log('[PillPalNow] OneSignal initialized successfully');

            injectBlockedModal();

            // Check Permission Status
            console.log('[PillPalNow] Notification permission:', Notification.permission);

            if (Notification.permission === 'denied') {
                console.log('[PillPalNow] Notifications are blocked, prompting user...');
                showBlockedModal();
            }

            // Listen for subscription changes to sync Player ID
            OneSignal.User.PushSubscription.addEventListener("change", function (event) {
                console.log('[PillPalNow] Subscription changed:', event);
                if (event.current.optedIn) {
                    const userId = OneSignal.User.PushSubscription.id;
                    if (userId) {
                        syncPlayerId(userId);
                    }
                }
            });

            // Initial Sync if already subscribed
            if (OneSignal.User.PushSubscription.optedIn) {
                const userId = OneSignal.User.PushSubscription.id;
                if (userId) {
                    syncPlayerId(userId);
                }
            }

        } catch (error) {
            console.error('[PillPalNow] OneSignal Init Error:', error);

            // Auto-recover from App ID mismatch (common when changing App IDs or DB corruption)
            if (error.toString().includes("AppID doesn't match") || error.toString().includes("IndexedDB")) {
                console.warn('[PillPalNow] Critical OneSignal Error. Performing hard reset...');

                // 1. Unregister Service Workers
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.getRegistrations().then(function (registrations) {
                        for (let registration of registrations) {
                            registration.unregister();
                            console.log('[PillPalNow] Unregistered SW:', registration);
                        }
                    });
                }

                // 2. Clear IndexedDB (Fixes 'Internal error opening backing store')
                if ('indexedDB' in window) {
                    const dbs = ['OneSignalSDK', 'OneSignal', 'ONE_SIGNAL_SDK_DB'];
                    dbs.forEach(dbName => {
                        const freq = indexedDB.deleteDatabase(dbName);
                        freq.onsuccess = function () {
                            console.log('[PillPalNow] Deleted Database:', dbName);
                        };
                        freq.onerror = function (e) {
                            console.warn('[PillPalNow] Failed to delete Database:', dbName, e);
                        };
                    });
                }

                // 3. Clear LocalStorage
                localStorage.removeItem('onesignal-notification-prompt');
                localStorage.removeItem('OneSignal-User-Id');

                console.log('[PillPalNow] Reset complete. Please reload the page manually.');
            }
        }
    });

    function syncPlayerId(playerId) {
        console.log('[PillPalNow] Syncing Player ID:', playerId);
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'store_onesignal_player_id',
                player_id: playerId,
                nonce: config.nonce
            },
            success: function (response) {
                console.log('[PillPalNow] Player ID sync response:', response);
            },
            error: function (xhr, status, error) {
                console.error('[PillPalNow] Player ID sync error:', error);
            }
        });
    }

    // Helper for manual trigger (e.g. from a button)
    window.pillpalnowRequestNotificationPermission = function () {
        OneSignalDeferred.push(async function (OneSignal) {
            if (Notification.permission === 'denied') {
                showBlockedModal();
            } else {
                OneSignal.Slidedown.promptPush();
            }
        });
    };

})(jQuery);
