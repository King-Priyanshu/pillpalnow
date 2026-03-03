<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0B101E">

    <!-- Early WebView Detection - Runs immediately to prevent flash of header -->
    <script>
        (function () {
            var ua = navigator.userAgent || '';
            var isWebView = (
                ua.indexOf('AppMySite') !== -1 ||
                ua.indexOf('wv') !== -1 ||
                ua.indexOf('WebView') !== -1 ||
                (ua.indexOf('Android') !== -1 && ua.indexOf('Version/') !== -1) ||
                window.location.search.indexOf('webview') !== -1
            );
            if (isWebView) {
                document.documentElement.classList.add('webview-mode', 'is-app');
                // Set cookie for PHP detection on next load
                document.cookie = 'pillpalnow_webview=1; path=/; max-age=31536000; SameSite=Lax';
            } else {
                // Ensure cookie is cleared if not in WebView (Fixes stuck mobile view on desktop)
                document.cookie = 'pillpalnow_webview=; path=/; max-age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
            }
        })();
    </script>

    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
    <?php wp_body_open(); ?>



    <div class="app-container">

        <!-- Top Navigation -->
        <nav class="top-nav">
            <div class="container">
                <div class="flex items-center gap-2">
                    <img src="<?php echo get_template_directory_uri(); ?>/assets/logo/image.svg" alt="PillPalNow" style="height: 32px;">
                    <!-- <span class="text-xl font-bold text-primary">PillPalNow</span> -->
                </div>

                <!-- Nav Links (Scrollable on Mobile) -->
                <div class="nav-links">
                    <a href="<?php echo home_url('/dashboard'); ?>"
                        class="nav-item <?php echo is_page('dashboard') ? 'active' : ''; ?>">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="7"></rect>
                            <rect x="14" y="3" width="7" height="7"></rect>
                            <rect x="14" y="14" width="7" height="7"></rect>
                            <rect x="3" y="14" width="7" height="7"></rect>
                        </svg>
                        <?php esc_html_e('Dashboard', 'pillpalnow'); ?>
                    </a>
                    <a href="<?php echo home_url('/history'); ?>"
                        class="nav-item <?php echo is_page('history') ? 'active' : ''; ?>">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <?php esc_html_e('History', 'pillpalnow'); ?>
                    </a>
                    <a href="<?php echo home_url('/refills'); ?>"
                        class="nav-item <?php echo is_page('refills') ? 'active' : ''; ?>">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z">
                            </path>
                            <line x1="7" y1="7" x2="7.01" y2="7"></line>
                        </svg>
                        <?php esc_html_e('Refills', 'pillpalnow'); ?>
                    </a>
                    <?php
                    $show_subscription = false;
                    if (is_user_logged_in()) {
                        $uid = get_current_user_id();
                        if (class_exists('Stripe_SaaS_Access')) {
                            // Only show "Subscription" if they have an active tier/plan
                            // Otherwise show "Pricing" even if they are in "No-CC" trial
                            $tier = Stripe_SaaS_Access::get_tier($uid);
                            $status = Stripe_SaaS_Access::get_status($uid);
                            if (!empty($tier) && in_array($status, ['active', 'trialing', 'permanent'])) {
                                $show_subscription = true;
                            }
                        }
                    }
                    ?>
                    <?php if ($show_subscription) : ?>
                    <a href="<?php echo home_url('/subscription'); ?>"
                        class="nav-item <?php echo is_page('subscription') ? 'active' : ''; ?>">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <?php esc_html_e('Subscription', 'pillpalnow'); ?>
                    </a>
                    <?php else : ?>
                    <a href="<?php echo home_url('/choose-plan/'); ?>"
                        class="nav-item <?php echo is_page('choose-plan') ? 'active' : ''; ?>">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="2" width="6" height="20" rx="1"></rect>
                            <circle cx="12" cy="7" r="2"></circle>
                            <path d="M12 14v3"></path>
                        </svg>
                        <?php esc_html_e('Pricing', 'pillpalnow'); ?>
                    </a>
                    <?php endif; ?>

                    <a href="<?php echo home_url('/account'); ?>"
                        class="nav-item <?php echo is_page('Profile') ? 'active' : ''; ?>">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <?php esc_html_e('Profile', 'pillpalnow'); ?>
                    </a>

                </div>

                <!-- Notification Bell -->
                <div class="notification-bell-container" style="position: relative; z-index: 9999;">
                    <?php
                    $notif_count = 0;
                    if (is_user_logged_in()) {
                        if (class_exists('PillPalNow_Notifications')) {
                            $notif_count = PillPalNow_Notifications::get_unread_count(get_current_user_id());
                        }
                    }
                    ?>
                    <button id="notification-bell" type="button" class="notification-bell-btn"
                        aria-label="Notifications" style="cursor: pointer; z-index: 99999;"
                        onclick="event.preventDefault(); event.stopPropagation(); var d=document.getElementById('notification-dropdown'); if(d){ if(d.style.display === 'block') { d.style.display = 'none'; d.classList.add('hidden'); d.classList.remove('show'); } else { d.classList.remove('hidden'); d.classList.add('show'); var rect = this.getBoundingClientRect(); var top = rect.bottom + 10; var right = window.innerWidth - rect.right; d.style.cssText = 'display: block !important; visibility: visible !important; opacity: 1 !important; position: fixed !important; top: ' + top + 'px !important; right: ' + right + 'px !important; z-index: 999999 !important; background-color: #1f2937 !important; color: white; border: 1px solid #374151; width: 320px; border-radius: 0.75rem;'; if(window.PillPalNowNotifications) window.PillPalNowNotifications.loadNotifications(); } }">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            style="pointer-events: none;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9">
                            </path>
                        </svg>
                        <span class="notification-badge" data-count="<?php echo esc_attr($notif_count); ?>"
                            style="<?php echo $notif_count > 0 ? 'display: flex;' : 'display: none;'; ?>"><?php echo esc_html($notif_count); ?></span>
                    </button>

                    <!-- Notification Dropdown -->
                    <div id="notification-dropdown" class="notification-dropdown hidden"
                        style="background-color: #111827; color: white; border: 1px solid #374151;">
                        <div class="notification-header">
                            <h3>Notifications</h3>
                        </div>
                        <div id="notification-list" class="notification-list">
                            <!-- Notifications will be loaded via JavaScript -->
                        </div>
                    </div>
                </div>

                <div class="profile-dropdown-container">
                    <button id="profileDropdownBtn" class="profile-avatar text-white" aria-expanded="false"
                        type="button"
                        onclick="event.preventDefault(); event.stopPropagation(); var m=document.getElementById('profileDropdownMenu'); if(m){ if(m.style.display === 'block'){ m.style.display='none'; m.classList.remove('show'); } else { m.classList.add('show'); var rect = this.getBoundingClientRect(); var top = rect.bottom + 10; var right = window.innerWidth - rect.right; m.style.cssText = 'display: block !important; visibility: visible !important; opacity: 1 !important; position: fixed !important; top: ' + top + 'px !important; right: ' + right + 'px !important; z-index: 999999 !important; background-color: #1f2937 !important; color: white; border: 1px solid #374151; min-width: 150px; border-radius: 0.75rem; padding: 0.5rem;'; } }">
                        <?php echo strtoupper(substr(wp_get_current_user()->display_name ?: 'Guest', 0, 1)); ?>
                    </button>

                    <div id="profileDropdownMenu" class="profile-dropdown-menu" style="display: none;">
                        <a href="<?php echo wp_logout_url(home_url('/login')); ?>" class="dropdown-item text-danger">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1">
                                </path>
                            </svg>
                            <?php esc_html_e('Logout', 'pillpalnow'); ?>
                        </a>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const btn = document.getElementById('profileDropdownBtn');
                        const menu = document.getElementById('profileDropdownMenu');

                        if (btn && menu) {
                            btn.addEventListener('click', function (e) {
                                e.stopPropagation();
                                const isExpanded = btn.getAttribute('aria-expanded') === 'true';
                                btn.setAttribute('aria-expanded', !isExpanded);
                                menu.classList.toggle('show');
                            });

                            document.addEventListener('click', function (e) {
                                if (!menu.contains(e.target) && !btn.contains(e.target)) {
                                    menu.classList.remove('show');
                                    btn.setAttribute('aria-expanded', 'false');
                                }
                            });
                        }
                    });

                    // OneSignal Notification Permission Handling
                    (function () {
                        'use strict';

                        // Check if notifications are supported
                        if (!('Notification' in window)) {
                            console.log('This browser does not support notifications.');
                            return;
                        }

                        // Detect browser for specific instructions
                        function getBrowser() {
                            const ua = navigator.userAgent;
                            if (ua.includes('Chrome') && !ua.includes('Edg')) return 'chrome';
                            if (ua.includes('Firefox')) return 'firefox';
                            if (ua.includes('Safari') && !ua.includes('Chrome')) return 'safari';
                            if (ua.includes('Edg')) return 'edge';
                            return 'unknown';
                        }

                        const browser = getBrowser();

                        // Inject modals
                        function injectModals() {
                            const modalsHtml = `
                                <div id="notification-permission-modal" class="notification-modal" style="display:none;">
                                    <div class="modal-backdrop" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
                                        <div class="modal-content" style="background:white;padding:30px;border-radius:12px;max-width:450px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.15);position:relative;">
                                            <div class="modal-icon" style="background:#e0f2fe;width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                                                <svg width="30" height="30" fill="none" stroke="#0284c7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                                            </div>
                                            <button class="modal-close" style="position:absolute;top:15px;right:15px;background:none;border:none;cursor:pointer;color:#999;font-size:20px;">&times;</button>
                                            <h3 style="margin:0 0 10px;color:#111827;font-size:20px;font-weight:600;">Enable Notifications</h3>
                                            <p style="margin:0 0 20px;color:#6b7280;line-height:1.5;">Stay updated with your medication schedule. We'll send you reminders so you never muss a dose.</p>
                                            <div class="modal-actions" style="display:flex;gap:10px;justify-content:center;">
                                                <button class="modal-btn-secondary" style="padding:10px 20px;border-radius:6px;border:1px solid #d1d5db;background:white;color:#374151;cursor:pointer;font-weight:500;">Not Now</button>
                                                <button class="modal-btn-primary" style="padding:10px 20px;border-radius:6px;border:none;background:#2563eb;color:white;cursor:pointer;font-weight:500;box-shadow:0 1px 2px rgba(0,0,0,0.05);">Enable</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div id="notification-blocked-modal" class="notification-modal" style="display:none;">
                                    <div class="modal-backdrop" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
                                        <div class="modal-content" style="background:white;padding:30px;border-radius:12px;max-width:450px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.15);position:relative;">
                                             <div class="modal-icon" style="background:#fee2e2;width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                                                <svg width="30" height="30" fill="none" stroke="#dc2626" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>
                                            </div>
                                            <button class="modal-close" style="position:absolute;top:15px;right:15px;background:none;border:none;cursor:pointer;color:#999;font-size:20px;">&times;</button>
                                            <h3 style="margin:0 0 10px;color:#111827;font-size:20px;font-weight:600;">Notifications Blocked</h3>
                                            <p style="margin:0 0 15px;color:#6b7280;line-height:1.5;">We can't send reminders because notifications are blocked in your browser settings.</p>
                                            <div id="browser-guide" class="browser-guide" style="background:#f9fafb;padding:15px;border-radius:8px;text-align:left;margin-bottom:20px;font-size:14px;border:1px solid #e5e7eb;">
                                                <!-- Dynamic content -->
                                            </div>
                                            <button class="modal-btn-primary" style="padding:10px 20px;border-radius:6px;border:none;background:#2563eb;color:white;cursor:pointer;font-weight:500;width:100%;">I've Enabled Them</button>
                                        </div>
                                    </div>
                                </div>
                            `;
                            document.body.insertAdjacentHTML('beforeend', modalsHtml);

                            // Bind events
                            bindModalEvents();
                        }

                        function bindModalEvents() {
                            // Permission modal
                            const permModal = document.getElementById('notification-permission-modal');
                            const permClose = permModal.querySelector('.modal-close');
                            const permNotNow = permModal.querySelector('.modal-btn-secondary');
                            const permEnable = permModal.querySelector('.modal-btn-primary');

                            const closePermModal = () => {
                                permModal.style.display = 'none';
                            };

                            permClose.addEventListener('click', closePermModal);
                            permNotNow.addEventListener('click', closePermModal);
                            permEnable.addEventListener('click', () => {
                                requestPermission();
                                closePermModal();
                            });

                            // Blocked modal
                            const blockedModal = document.getElementById('notification-blocked-modal');
                            const blockedClose = blockedModal.querySelector('.modal-close');
                            const blockedDone = blockedModal.querySelector('.modal-btn-primary');

                            const closeBlockedModal = () => {
                                blockedModal.style.display = 'none';
                            };

                            blockedClose.addEventListener('click', closeBlockedModal);
                            blockedDone.addEventListener('click', () => {
                                // Check permission again
                                if (Notification.permission === 'granted') {
                                    closeBlockedModal();
                                    // Could trigger OneSignal subscription here if available
                                } else {
                                    alert('Please follow the instructions above to enable notifications.');
                                }
                            });
                        }

                        function showPermissionModal() {
                            const modal = document.getElementById('notification-permission-modal');
                            modal.style.display = 'block';
                        }

                        function showBlockedModal() {
                            const modal = document.getElementById('notification-blocked-modal');
                            const guideEl = document.getElementById('browser-guide');

                            let instructions = '';
                            switch (browser) {
                                case 'chrome':
                                    instructions = `
                                        <ol style="margin:0;padding-left:20px;color:#333333 !important;">
                                            <li>Click the lock icon (🔒) in the address bar</li>
                                            <li>Select "Site settings"</li>
                                            <li>Find "Notifications" and set to "Allow"</li>
                                            <li>Refresh the page</li>
                                        </ol>
                                    `;
                                    break;
                                case 'firefox':
                                    instructions = `
                                        <ol style="margin:0;padding-left:20px;color:#333333 !important;">
                                            <li>Click the shield icon (🛡️) in the address bar</li>
                                            <li>Select "Allow notifications"</li>
                                            <li>Find this site and set to Allow</li>
                                        </ol>
                                    `;
                                    break;
                                case 'safari':
                                    instructions = `
                                        <ol style="margin:0;padding-left:20px;color:#333333 !important;">
                                            <li>Go to Safari > Preferences > Websites > Notifications</li>
                                            <li>Find this website and select "Allow"</li>
                                        </ol>
                                    `;
                                    break;
                                case 'edge':
                                    instructions = `
                                        <ol style="margin:0;padding-left:20px;color:#333333 !important;">
                                            <li>Click the lock icon (🔒) in the address bar</li>
                                            <li>Select "Permissions for this site"</li>
                                            <li>Find "Notifications" and set to "Allow"</li>
                                            <li>Refresh the page</li>
                                        </ol>
                                    `;
                                    break;
                                default:
                                    instructions = `
                                        <p>Look for a lock or info icon in your browser's address bar, click it, and allow notifications for this site.</p>
                                    `;
                            }

                            guideEl.innerHTML = instructions;
                            modal.style.display = 'block';
                        }

                        function requestPermission() {
                            // Use OneSignal's correct subscription method if available
                            if (window.OneSignalDeferred) {
                                window.OneSignalDeferred.push(async function (OneSignal) {
                                    console.log('Requesting OneSignal permission via OptIn...');
                                    try {
                                        await OneSignal.User.PushSubscription.optIn();
                                        console.log('OneSignal OptIn called.');
                                    } catch (e) {
                                        console.error('OneSignal OptIn failed:', e);
                                        // Fallback to native if OneSignal fails strictly
                                        requestNativePermission();
                                    }
                                });
                            } else {
                                // Fallback for when OneSignal isn't loaded yet
                                requestNativePermission();
                            }
                        }

                        function requestNativePermission() {
                            if (Notification.permission === 'default') {
                                Notification.requestPermission().then(permission => {
                                    console.log('Native Notification permission:', permission);
                                    if (permission === 'granted') {
                                        // Try to init OneSignal again if possible, or reload
                                        if (window.OneSignalDeferred) {
                                            window.OneSignalDeferred.push(function (OneSignal) {
                                                OneSignal.User.PushSubscription.optIn();
                                            });
                                        }
                                    } else if (permission === 'denied') {
                                        showBlockedModal();
                                    }
                                });
                            }
                        }

                        // Initialize
                        injectModals();

                        // Check permission status
                        if (Notification.permission === 'default') {
                            // Show permission modal after a short delay
                            setTimeout(showPermissionModal, 2000);
                        } else if (Notification.permission === 'denied') {
                            // Show blocked modal
                            setTimeout(showBlockedModal, 2000);
                        }

                    })();
                </script>
            </div>
        </nav>
        


    </header>