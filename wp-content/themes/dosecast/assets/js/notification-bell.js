/**
 * PillPalNow Notification Bell
 * Handles in-app notification display and interactions
 */
(function () {
    'use strict';

    const NotificationBell = {
        bell: null,
        dropdown: null,
        badge: null,
        notificationList: null,
        isOpen: false,
        apiUrl: '',
        nonce: '',

        init: function () {
            // Check if API helper is available
            if (typeof PillPalNowAPI === 'undefined') {
                console.error('[PillPalNow] API Helper (PillPalNowAPI) is missing');
                return;
            }

            console.log('[PillPalNow] Notification Bell initializing');

            // Get DOM elements
            this.bell = document.getElementById('notification-bell');
            if (!this.bell) {
                console.error('[PillPalNow] Notification Bell element #notification-bell NOT FOUND');
            } else {
                console.log('[PillPalNow] Notification Bell element found');
            }
            this.dropdown = document.getElementById('notification-dropdown');
            this.badge = document.querySelector('.notification-badge');
            this.notificationList = document.getElementById('notification-list');

            if (!this.bell || !this.dropdown) {
                return; // Bell not present on this page
            }

            // Event listeners for open/close are handled inline or via global click for closing

            // Load unread count on page load
            this.loadUnreadCount();

            // Close dropdown when clicking outside
            document.addEventListener('click', this.handleClickOutside.bind(this));
            document.addEventListener('touchstart', this.handleClickOutside.bind(this), { passive: true });
        },

        /**
         * Toggle dropdown open/close
         */
        toggleDropdown: function (e) {
            console.log('[PillPalNow] toggleDropdown called');
            e.stopPropagation();

            if (this.isOpen) {
                this.closeDropdown();
            } else {
                this.openDropdown();
            }
        },

        /**
         * Open notification dropdown and load notifications
         */
        openDropdown: function () {
            this.isOpen = true;
            this.dropdown.classList.remove('hidden');
            // Force reflow to ensure transition works
            void this.dropdown.offsetHeight;
            this.dropdown.classList.add('show');

            // Load notifications
            this.loadNotifications();
        },

        /**
         * Close notification dropdown
         */
        closeDropdown: function () {
            this.isOpen = false;
            this.dropdown.classList.remove('show');
            // Wait for transition to complete before hiding
            setTimeout(() => {
                if (!this.isOpen) {
                    this.dropdown.classList.add('hidden');
                }
            }, 150); // Match CSS transition duration
        },

        /**
         * Handle clicks outside dropdown to close it
         */
        handleClickOutside: function (e) {
            if (this.isOpen && !this.bell.contains(e.target) && !this.dropdown.contains(e.target)) {
                this.closeDropdown();
            }
        },

        /**
         * Load unread notification count
         */
        loadUnreadCount: function () {
            // Always bypass cache for unread count to get fresh data
            PillPalNowAPI.get('notifications/unread-count', { cache: false })
                .then(data => {
                    if (data.success && data.count !== undefined) {
                        this.updateBadge(data.count);
                    }
                })
                .catch(error => {
                    // Only log if it's not a 404/silent failing
                    if (error.message && error.message.indexOf('404') === -1) {
                        console.error('Error loading unread count:', error);
                    }
                });
        },

        /**
         * Invalidate all notification-related caches
         */
        invalidateCache: function () {
            if (typeof PillPalNowAPI !== 'undefined' && PillPalNowAPI.cache) {
                // Clear specific notification cache keys
                var config = PillPalNowAPI.getConfig();
                var baseUrl = config.baseUrl;
                PillPalNowAPI.cache.remove(baseUrl + 'notifications/unread-count');
                PillPalNowAPI.cache.remove(baseUrl + 'notifications?status=unread&limit=20');
            }
        },

        /**
         * Update badge with unread count
         */
        updateBadge: function (count) {
            console.log('[PillPalNow] Updating badge count to:', count);
            if (!this.badge) {
                console.error('[PillPalNow] Badge element not found!');
                return;
            }

            this.badge.setAttribute('data-count', count);

            if (count > 0) {
                this.badge.textContent = count > 9 ? '9+' : count;
                this.badge.style.setProperty('display', 'flex', 'important');
            } else {
                this.badge.textContent = '0';
                this.badge.style.setProperty('display', 'none', 'important');
            }
        },

        /**
         * Load notifications from API
         */
        loadNotifications: function () {
            // Show loading indicator
            this.notificationList.innerHTML = '<div class="notification-loading">Loading...</div>';

            PillPalNowAPI.get('notifications?status=unread&limit=20')
                .then(data => {
                    if (data.success && data.notifications) {
                        this.renderNotifications(data.notifications);
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    this.notificationList.innerHTML = '<div class="notification-error">Failed to load notifications</div>';
                });
        },

        /**
         * Render notifications in dropdown
         */
        renderNotifications: function (notifications) {
            if (notifications.length === 0) {
                this.notificationList.innerHTML = '<div class="notification-empty">📬 No new notifications</div>';
                return;
            }

            let html = '';
            notifications.forEach(notif => {
                const icon = this.getNotificationIcon(notif.type);
                const timeAgo = this.getTimeAgo(notif.created_timestamp);

                html += `
                    <div class="notification-item ${notif.status === 'unread' ? 'unread' : 'read'}" 
                         data-id="${notif.id}" 
                         data-url="${notif.related_url || '#'}">
                        <div class="notification-icon ${notif.type}">${icon}</div>
                        <div class="notification-content">
                            <div class="notification-title">${this.escapeHtml(notif.title)}</div>
                            <div class="notification-message">${this.escapeHtml(notif.message)}</div>
                            <div class="notification-time">${timeAgo}</div>
                        </div>
                        <button class="notification-delete" title="Delete" data-id="${notif.id}" style="background: transparent; border: none; cursor: pointer; color: #9ca3af; padding: 4px; border-radius: 4px; transition: all 0.2s; display: flex; align-items: center; justify-content: center; width: 24px; height: 24px;" onmouseover="this.style.color='#ef4444'; this.style.backgroundColor='rgba(239, 68, 68, 0.1)'" onmouseout="this.style.color='#9ca3af'; this.style.backgroundColor='transparent'">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                `;
            });

            this.notificationList.innerHTML = html;

            // Add click handlers
            this.notificationList.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', this.handleNotificationClick.bind(this));
            });

            // Add delete handlers
            this.notificationList.querySelectorAll('.notification-delete').forEach(btn => {
                btn.addEventListener('click', this.handleDeleteClick.bind(this));
            });
        },

        /**
         * Handle notification item click
         */
        handleNotificationClick: function (e) {
            // Ignore if clicking delete button
            if (e.target.closest('.notification-delete')) return;

            const item = e.currentTarget;
            const notifId = item.getAttribute('data-id');
            const url = item.getAttribute('data-url');

            // Mark as read
            this.markAsRead([notifId], () => {
                // Navigate to related URL
                if (url && url !== '#') {
                    window.location.href = url;
                } else {
                    // Just refresh to update badge
                    this.closeDropdown();
                    this.loadUnreadCount();
                }
            });
        },

        /**
         * Handle delete click
         */
        handleDeleteClick: function (e) {
            e.stopPropagation();
            const btn = e.currentTarget;
            const notifId = btn.getAttribute('data-id');
            const item = btn.closest('.notification-item');

            // Optimistic UI removal
            item.style.opacity = '0.5';

            PillPalNowAPI.post('notifications/delete', {
                notification_ids: [notifId]
            })
                .then(data => {
                    if (data.success) {
                        // Remove from DOM with animation
                        item.style.height = item.offsetHeight + 'px';
                        setTimeout(() => {
                            item.style.height = '0';
                            item.style.opacity = '0';
                            item.style.padding = '0';
                            item.style.margin = '0';
                            setTimeout(() => item.remove(), 300);
                        }, 10);

                        // Optimistic UI update for badge
                        if (item.classList.contains('unread')) {
                            const currentCount = parseInt(this.badge.getAttribute('data-count')) || 0;
                            this.updateBadge(Math.max(0, currentCount - 1));
                        }

                        // Invalidate cache and refresh count from server (as backup)
                        this.invalidateCache();
                        this.loadUnreadCount();
                    } else {
                        // Revert
                        item.style.opacity = '1';
                    }
                })
                .catch(error => {
                    console.error('Error deleting notification:', error);
                    item.style.opacity = '1';
                });
        },

        /**
         * Mark notifications as read
         */
        markAsRead: function (notificationIds, callback) {
            PillPalNowAPI.post('notifications/mark-read', {
                notification_ids: notificationIds
            })
                .then(data => {
                    if (data.success) {
                        // Optimistic UI update for badge
                        const currentCount = parseInt(this.badge.getAttribute('data-count')) || 0;
                        this.updateBadge(Math.max(0, currentCount - notificationIds.length));

                        // Invalidate cache and refresh count from server (as backup)
                        this.invalidateCache();
                        this.loadUnreadCount();

                        if (callback) callback();
                    }
                })
                .catch(error => console.error('Error marking as read:', error));
        },

        /**
         * Get icon for notification type
         */
        getNotificationIcon: function (type) {
            const icons = {
                'assigned': '📋',
                'reminder': '⏰',
                'taken': '✅',
                'skipped': '⏭️',
                'missed': '❌',
                'postponed': '⏸️',
                'refill_low': '⚠️'
            };
            return icons[type] || '🔔';
        },

        /**
         * Get relative time string
         */
        getTimeAgo: function (timestamp) {
            const now = Math.floor(Date.now() / 1000);
            const diff = now - timestamp;

            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
            if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
            if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
            return new Date(timestamp * 1000).toLocaleDateString();
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function (text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            NotificationBell.init();
        });
    } else {
        NotificationBell.init();
    }

    // Expose for debugging
    window.PillPalNowNotifications = NotificationBell;
})();
