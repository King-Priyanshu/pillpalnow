/**
 * PillPalNow Admin JavaScript - Enhanced Edition
 * Professional AJAX handling with tab management, toast notifications, and testing features
 */

(function ($) {
    'use strict';

    const PillPalNowAdmin = {
        toastQueue: [],
        toastTimeout: null,

        init: function () {
            this.bindEvents();
            this.initProgressBars();
            this.initTabs();
            this.initToastContainer();
            this.loadRecentLogs();
        },

        bindEvents: function () {
            // Bulk actions
            $(document).on('submit', '#bulk-action-form', this.handleBulkAction.bind(this));

            // Test send
            $(document).on('submit', '.pillpalnow-test-send form', this.handleTestSend.bind(this));

            // Single resend
            $(document).on('submit', '.log-row form', this.handleSingleResend.bind(this));

            // File upload with progress
            $(document).on('change', 'input[name="custom_service_worker_file"]', this.handleFileUpload.bind(this));

            // Toggle details
            $(document).on('click', '.toggle-details', this.toggleDetails.bind(this));

            // Filter form
            $(document).on('submit', '.pillpalnow-filters form', this.handleFilter.bind(this));

            // Filter form
            $(document).on('submit', '.pillpalnow-filters form', this.handleFilter.bind(this));

            // Tab navigation
            $(document).on('click', '.pillpalnow-tab-wrapper .nav-tab', this.handleTabClick.bind(this));

            // System status refresh
            $(document).on('click', '#refresh-system-status', this.refreshSystemStatus.bind(this));

            // OneSignal connection test
            $(document).on('click', '#test-onesignal-connection', this.testOneSignalConnection.bind(this));

            // Send test notification
            $(document).on('click', '#send-test-notification', this.sendTestNotification.bind(this));

            // Debug actions
            $(document).on('click', '#refresh-debug-info', this.refreshDebugInfo.bind(this));
            $(document).on('click', '#export-debug-report', this.exportDebugReport.bind(this));
            $(document).on('click', '#clear-logs', this.clearLogs.bind(this));

            // Keyboard shortcuts
            $(document).on('keydown', this.handleKeyboardShortcuts.bind(this));
        },

        initProgressBars: function () {
            $('.progress-container').each(function () {
                const $container = $(this);
                const $bar = $container.find('.progress-fill');
                const progress = $container.data('progress') || 0;
                $bar.css('width', progress + '%');
            });
        },

        initTabs: function () {
            // Get active tab from URL hash or query param
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            const hash = window.location.hash.replace('#tab-', '');

            const activeTab = hash || tabParam || 'general';
            this.switchTab(activeTab);

            // Handle browser back/forward
            $(window).on('hashchange', () => {
                const newHash = window.location.hash.replace('#tab-', '');
                if (newHash) {
                    this.switchTab(newHash);
                }
            });
        },

        initToastContainer: function () {
            if ($('#pillpalnow-toast-container').length === 0) {
                $('body').append('<div id="pillpalnow-toast-container"></div>');
            }
        },

        loadRecentLogs: function () {
            const $logsContainer = $('#recent-logs-container');
            if ($logsContainer.length === 0) return;

            // Show loading state
            $logsContainer.html('<p style="text-align: center; padding: 20px;"><span class="dashicons dashicons-update spin" style="font-size: 20px;"></span> Loading recent logs...</p>');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_get_recent_logs',
                    limit: 20,
                    nonce: pillpalnow_ajax.nonce
                },
                success: (response) => {
                    if (response.success && response.data && response.data.logs && response.data.logs.length > 0) {
                        this.renderLogs(response.data.logs, $logsContainer);
                    } else {
                        $logsContainer.html(`
                            <div style="text-align: center; padding: 30px; background: var(--bg-secondary); border-radius: 8px;">
                                <span class="dashicons dashicons-info" style="font-size: 40px; color: var(--text-secondary); margin-bottom: 10px;"></span>
                                <p style="color: var(--text-secondary); margin: 0;">No notification logs found yet.</p>
                                <p style="color: var(--text-secondary); font-size: 13px; margin-top: 5px;">Logs will appear here when notifications are sent.</p>
                            </div>
                        `);
                    }
                },
                error: (xhr, status, error) => {
                    $logsContainer.html(`
                        <div class="notice notice-error" style="margin: 0;">
                            <p><strong>Failed to load logs</strong></p>
                            <p>Error: ${error || 'Unknown error'}</p>
                            <button type="button" class="button" onclick="PillPalNowAdmin.loadRecentLogs()">Retry</button>
                        </div>
                    `);
                    this.showToast('Failed to load recent logs', 'error');
                }
            });
        },

        renderLogs: function (logs, $container) {
            if (!logs || logs.length === 0) {
                $container.html('<p>No recent logs.</p>');
                return;
            }

            let html = '<div class="logs-list">';
            logs.forEach(log => {
                const statusClass = log.status || 'unknown';
                html += `<div class="log-entry log-${statusClass}">
                    <div class="log-meta">
                        <span class="log-date">${log.created_at || 'N/A'}</span>
                        <span class="log-status badge-${statusClass}">${(log.status || 'unknown').toUpperCase()}</span>
                    </div>
                    <div class="log-details">
                        <strong>${log.notification_type || 'Unknown'}</strong> via ${log.provider || 'Unknown'}
                        <br><em>${log.message || 'No message'}</em>
                    </div>
                </div>`;
            });
            html += '</div>';
            $container.html(html);
        },

        handleTabClick: function (e) {
            e.preventDefault();
            const $tab = $(e.currentTarget);
            const tabName = $tab.data('tab');

            this.switchTab(tabName);

            // Update URL hash
            window.location.hash = 'tab-' + tabName;
        },

        switchTab: function (tabName) {
            // Hide all tab contents
            $('.pillpalnow-tab-content').hide();

            // Show selected tab
            $('#tab-' + tabName).show();

            // Update nav tabs
            $('.pillpalnow-tab-wrapper .nav-tab').removeClass('nav-tab-active');
            $('.pillpalnow-tab-wrapper .nav-tab[data-tab="' + tabName + '"]').addClass('nav-tab-active');
        },

        showToast: function (message, type = 'success', duration = 5000) {
            const $container = $('#pillpalnow-toast-container');
            const toastId = 'toast-' + Date.now();

            const $toast = $(`
                <div class="pillpalnow-toast toast-${type}" id="${toastId}">
                    <div class="toast-content">
                        <span class="toast-icon"></span>
                        <span class="toast-message">${message}</span>
                    </div>
                    <button class="toast-close">&times;</button>
                    <div class="toast-progress"></div>
                </div>
            `);

            $container.append($toast);

            // Trigger animation
            setTimeout(() => {
                $toast.addClass('show');
            }, 10);

            // Auto dismiss
            if (duration > 0) {
                const $progress = $toast.find('.toast-progress');
                $progress.css('animation', `toast-progress ${duration}ms linear`);

                setTimeout(() => {
                    this.hideToast($toast);
                }, duration);
            }

            // Manual dismiss
            $toast.find('.toast-close').on('click', () => {
                this.hideToast($toast);
            });
        },

        hideToast: function ($toast) {
            $toast.removeClass('show');
            setTimeout(() => {
                $toast.remove();
            }, 300);
        },

        refreshSystemStatus: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const originalHtml = $btn.html();

            $btn.html('<span class="dashicons dashicons-update spin"></span> Refreshing...');
            $btn.prop('disabled', true);

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_check_system_status',
                    nonce: pillpalnow_ajax.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showToast('System status refreshed successfully', 'success');
                        // Reload page to show updated status
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        this.showToast('Failed to refresh system status', 'error');
                    }
                },
                error: () => {
                    this.showToast('Network error occurred', 'error');
                },
                complete: () => {
                    $btn.html(originalHtml);
                    $btn.prop('disabled', false);
                }
            });
        },

        testOneSignalConnection: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const $result = $('#onesignal-test-result');

            const appId = $('input[name="pillpalnow_notification_settings[onesignal_app_id]"]').val();
            const apiKey = $('input[name="pillpalnow_notification_settings[onesignal_api_key]"]').val();

            if (!appId || !apiKey) {
                $result.html('<div class="notice notice-error"><p>Please enter both App ID and API Key first.</p></div>');
                return;
            }

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Testing...');
            $result.html('<div class="notice notice-info"><p>Connecting to OneSignal...</p></div>');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_test_onesignal',
                    app_id: appId,
                    api_key: apiKey,
                    nonce: pillpalnow_ajax.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const data = response.data;
                        const appName = (data.data && data.data.name) ? data.data.name : (data.app_name || 'N/A');
                        const players = (data.data && typeof data.data.players !== 'undefined') ? data.data.players : (data.players || 0);

                        $result.html(`
                            <div class="notice notice-success">
                                <p><strong>✓ Connection Successful!</strong></p>
                                <p>App Name: <strong>${appName}</strong></p>
                                <p>Total Players: <strong>${players}</strong></p>
                            </div>
                        `);
                        this.showToast('OneSignal connection successful!', 'success');
                    } else {
                        const errorData = response.data || {};
                        let errorHtml = `
                            <div class="notice notice-error">
                                <p><strong>✗ ${errorData.message || 'Connection Failed'}</strong></p>
                                <p>${errorData.error || 'Unknown error'}</p>
                        `;
                        if (errorData.help) {
                            errorHtml += `<p style="margin-top: 8px; padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107;"><strong>💡 How to fix:</strong> ${errorData.help}</p>`;
                        }
                        errorHtml += `</div>`;
                        $result.html(errorHtml);
                        this.showToast(errorData.message || 'OneSignal connection failed', 'error');
                    }
                },
                error: () => {
                    $result.html('<div class="notice notice-error"><p>Network error occurred</p></div>');
                    this.showToast('Network error occurred', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-plugins"></span> Test Connection');
                }
            });
        },

        sendTestNotification: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const $result = $('#test-notification-result');

            const type = $('#test-notification-type').val();
            const recipient = $('#test-recipient').val();
            const message = $('#test-message').val();

            if (!recipient) {
                $result.html('<div class="notice notice-error"><p>Please enter a recipient.</p></div>');
                return;
            }

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Sending...');
            $result.html('<div class="notice notice-info"><p>Sending notification...</p></div>');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_send_test_notification',
                    type: type,
                    recipient: recipient,
                    message: message,
                    nonce: pillpalnow_ajax.nonce
                },
                success: (response) => {
                    if (response.success) {
                        $result.html(`
                            <div class="notice notice-success">
                                <p><strong>✓ Notification Sent!</strong></p>
                                <p>${response.data.message || 'Test notification sent successfully'}</p>
                                ${response.data.notification_id ? `<p><small>ID: ${response.data.notification_id}</small></p>` : ''}
                            </div>
                        `);
                        this.showToast('Test notification sent successfully!', 'success');

                        // Clear form
                        $('#test-recipient').val('');
                        $('#test-message').val('');
                    } else {
                        $result.html(`
                            <div class="notice notice-error">
                                <p><strong>✗ Send Failed</strong></p>
                                <p>${response.data.message || 'Failed to send notification'}</p>
                            </div>
                        `);
                        this.showToast('Failed to send notification', 'error');
                    }
                },
                error: () => {
                    $result.html('<div class="notice notice-error"><p>Network error occurred</p></div>');
                    this.showToast('Network error occurred', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send Test Notification');
                }
            });
        },

        refreshDebugInfo: function (e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const $container = $('#debug-info-container');

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Loading...');
            $container.html('<p>Loading diagnostics...</p>');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_get_debug_info',
                    nonce: pillpalnow_ajax.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.renderDebugInfo(response.data, $container);
                        this.showToast('Debug info refreshed', 'success');
                    } else {
                        $container.html('<p class="error">Failed to load debug info</p>');
                    }
                },
                error: () => {
                    $container.html('<p class="error">Network error occurred</p>');
                },
                complete: () => {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Refresh Info');
                }
            });
        },

        renderDebugInfo: function (data, $container) {
            let html = '<div class="debug-info-sections">';

            // System Status
            if (data.system_status) {
                html += '<div class="debug-section"><h4>System Status</h4><pre>' +
                    JSON.stringify(data.system_status, null, 2) + '</pre></div>';
            }

            // Site Info
            if (data.site_info) {
                html += '<div class="debug-section"><h4>Site Information</h4><pre>' +
                    JSON.stringify(data.site_info, null, 2) + '</pre></div>';
            }

            html += '</div>';
            $container.html(html);
        },

        exportDebugReport: function (e) {
            e.preventDefault();
            window.location.href = pillpalnow_ajax.ajax_url + '?action=pillpalnow_export_debug_report&nonce=' + pillpalnow_ajax.nonce;
            this.showToast('Downloading diagnostic report...', 'info');
        },

        clearLogs: function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to clear all notification logs? This cannot be undone.')) {
                return;
            }

            const $btn = $(e.currentTarget);
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Clearing...');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_clear_error_logs',
                    nonce: pillpalnow_ajax.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showToast(response.data.message || 'Logs cleared successfully', 'success');
                        this.loadRecentLogs();
                    } else {
                        this.showToast('Failed to clear logs', 'error');
                    }
                },
                error: () => {
                    this.showToast('Network error occurred', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Clear Logs');
                }
            });
        },

        handleKeyboardShortcuts: function (e) {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                $('#pillpalnow-settings-form').submit();
            }
        },

        showLoading: function ($element, text = 'Processing...') {
            $element.addClass('loading');
            if (text) {
                const $loadingText = $('<div class="loading-text">' + text + '</div>');
                $element.append($loadingText);
            }
            return $element;
        },

        hideLoading: function ($element) {
            $element.removeClass('loading');
            $element.find('.loading-text').remove();
            return $element;
        },

        showProgress: function ($container, progress, text = '') {
            $container.show();
            const $bar = $container.find('.progress-fill');
            const $text = $container.find('.progress-text');
            $bar.css('width', progress + '%');
            if (text) {
                $text.text(text);
            } else {
                $text.text(progress + '%');
            }
        },

        hideProgress: function ($container) {
            $container.hide();
            const $bar = $container.find('.progress-fill');
            $bar.css('width', '0%');
        },

        showMessage: function (message, type = 'success', duration = 5000) {
            this.showToast(message, type, duration);
        },

        handleBulkAction: function (e) {
            e.preventDefault();
            const $form = $(e.target);
            const action = $form.find('select[name="action"]').val();

            if (!action) {
                this.showToast('Please select a bulk action.', 'warning');
                return;
            }

            if (!confirm('Are you sure you want to perform this bulk action? This cannot be undone.')) {
                return;
            }

            const $submitBtn = $form.find('button[type="submit"]');
            const originalText = $submitBtn.text();
            this.showLoading($submitBtn, 'Processing...');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_bulk_action',
                    bulk_action: action,
                    nonce: $form.find('input[name="_wpnonce"]').val()
                },
                success: (response) => {
                    this.hideLoading($submitBtn);
                    $submitBtn.text(originalText);
                    if (response.success) {
                        this.showToast(response.data.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        this.showToast(response.data.message || 'An error occurred.', 'error');
                    }
                },
                error: () => {
                    this.hideLoading($submitBtn);
                    $submitBtn.text(originalText);
                    this.showToast('Network error occurred. Please try again.', 'error');
                }
            });
        },

        handleTestSend: function (e) {
            e.preventDefault();
            const $form = $(e.target);
            const $submitBtn = $form.find('button[type="submit"]');
            const originalText = $submitBtn.text();
            this.showLoading($submitBtn, 'Sending...');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_test_send',
                    provider: $form.find('select[name="test_provider"]').val(),
                    email: $form.find('input[name="test_email"]').val(),
                    nonce: $form.find('input[name="_wpnonce"]').val()
                },
                success: (response) => {
                    this.hideLoading($submitBtn);
                    $submitBtn.text(originalText);
                    if (response.success) {
                        this.showToast(response.data.message, 'success');
                        $form[0].reset();
                    } else {
                        this.showToast(response.data.message || 'Failed to send test notification.', 'error');
                    }
                },
                error: () => {
                    this.hideLoading($submitBtn);
                    $submitBtn.text(originalText);
                    this.showToast('Network error occurred. Please try again.', 'error');
                }
            });
        },

        handleSingleResend: function (e) {
            e.preventDefault();
            const $form = $(e.target);
            const $submitBtn = $form.find('button[type="submit"]');
            const originalText = $submitBtn.text();
            this.showLoading($submitBtn, 'Resending...');

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'pillpalnow_resend_single',
                    log_id: $form.find('input[name="log_id"]').val(),
                    nonce: $form.find('input[name="_wpnonce"]').val()
                },
                success: (response) => {
                    this.hideLoading($submitBtn);
                    $submitBtn.text(originalText);
                    if (response.success) {
                        this.showToast(response.data.message, 'success');
                        const $row = $form.closest('.log-row');
                        $row.find('.status-badge').removeClass('status-failed').addClass('status-resent').text('RESENT');
                        $form.remove();
                    } else {
                        this.showToast(response.data.message || 'Failed to resend notification.', 'error');
                    }
                },
                error: () => {
                    this.hideLoading($submitBtn);
                    $submitBtn.text(originalText);
                    this.showToast('Network error occurred. Please try again.', 'error');
                }
            });
        },

        handleFileUpload: function (e) {
            const $input = $(e.target);
            const $form = $input.closest('form');
            const $progressContainer = $form.find('.progress-container');

            if (!$input[0].files.length) return;

            const file = $input[0].files[0];

            if (!file.name.endsWith('.js')) {
                this.showToast('Please select a valid JavaScript (.js) file.', 'error');
                $input.val('');
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                this.showToast('File size must be less than 5MB.', 'error');
                $input.val('');
                return;
            }

            this.showProgress($progressContainer, 0, 'Uploading...');

            const formData = new FormData();
            formData.append('action', 'pillpalnow_upload_service_worker');
            formData.append('file', file);
            formData.append('nonce', pillpalnow_ajax.nonce);

            $.ajax({
                url: pillpalnow_ajax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: () => {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', (e) => {
                        if (e.lengthComputable) {
                            const percentComplete = Math.round((e.loaded / e.total) * 100);
                            this.showProgress($progressContainer, percentComplete, 'Uploading... ' + percentComplete + '%');
                        }
                    });
                    return xhr;
                },
                success: (response) => {
                    this.hideProgress($progressContainer);
                    if (response.success) {
                        this.showToast(response.data.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        this.showToast(response.data.message || 'Upload failed.', 'error');
                        $input.val('');
                    }
                },
                error: () => {
                    this.hideProgress($progressContainer);
                    this.showToast('Upload failed. Please try again.', 'error');
                    $input.val('');
                }
            });
        },

        toggleDetails: function (e) {
            e.preventDefault();
            const $button = $(e.target);
            const $header = $button.closest('.user-group-header');
            const $details = $header.next('.user-group-details');
            $details.toggleClass('show');
            const isVisible = $details.hasClass('show');
            $button.text(isVisible ? 'Hide Details' : 'Toggle Details');
        },

        handleFilter: function (e) {
            e.preventDefault();
            const $form = $(e.target);
            const formData = $form.serialize();
            const newUrl = window.location.pathname + '?' + formData;
            window.location.href = newUrl;
        },


    };

    // Initialize when document is ready
    $(document).ready(function () {
        PillPalNowAdmin.init();
    });

    // Expose for debugging
    window.PillPalNowAdmin = PillPalNowAdmin;

})(jQuery);