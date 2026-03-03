/**
 * PillPalNow Subscription Dashboard JavaScript
 * 
 * Handles AJAX interactions for the subscription management dashboard:
 * - Stripe Customer Portal redirect
 * - Cancellation flow with confirmation (at period end OR immediate)
 * - Duplicate cancellation prevention
 * - Subscription reactivation
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
(function ($) {
    'use strict';

    const config = window.pillpalnowSubscription || {};
    const ajax = config.ajaxUrl || '/wp-admin/admin-ajax.php';
    const nonce = config.nonce || '';
    const i18n = config.i18n || {};

    /**
     * Show loading overlay
     */
    function showLoading(message) {
        const $overlay = $('#pillpalnow-loading');
        if (message) {
            $overlay.find('p').text(message);
        }
        $overlay.fadeIn(200);
    }

    /**
     * Hide loading overlay
     */
    function hideLoading() {
        $('#pillpalnow-loading').fadeOut(200);
    }

    /**
     * Show error message (toast-style)
     */
    function showError(message) {
        hideLoading();
        // Create or update toast
        let $toast = $('#pillpalnow-toast');
        if (!$toast.length) {
            $toast = $('<div id="pillpalnow-toast" class="pillpalnow-toast pillpalnow-toast-error"></div>');
            $('body').append($toast);
        }
        $toast.text(message).addClass('visible');
        setTimeout(() => $toast.removeClass('visible'), 5000);
    }

    /**
     * Manage Billing → Open Stripe Customer Portal
     */
    $(document).on('click', '#pillpalnow-manage-billing, #pillpalnow-update-payment', function (e) {
        e.preventDefault();
        showLoading(i18n.redirecting || 'Redirecting to billing portal...');

        $.post(ajax, {
            action: 'pillpalnow_get_portal_url',
            nonce: nonce,
        })
            .done(function (response) {
                if (response.success && response.data.url) {
                    window.location.href = response.data.url;
                } else {
                    showError(response.data?.message || i18n.error || 'Something went wrong.');
                }
            })
            .fail(function () {
                showError(i18n.error || 'Something went wrong. Please try again.');
            });
    });

    /**
     * Cancel Mode Radio Toggle — visual selection state
     */
    $(document).on('change', 'input[name="cancel_mode"]', function () {
        $('.pillpalnow-radio-option').removeClass('pillpalnow-radio-selected');
        $(this).closest('.pillpalnow-radio-option').addClass('pillpalnow-radio-selected');
    });

    /**
     * Confirm Cancellation
     */
    $(document).on('click', '#pillpalnow-confirm-cancel', function (e) {
        e.preventDefault();

        const $btn = $(this);

        // Prevent double-click
        if ($btn.prop('disabled') || $btn.data('processing')) {
            return;
        }

        const reason = $('#cancel-reason').val();
        const feedback = $('#cancel-feedback').val();
        const cancelMode = $('input[name="cancel_mode"]:checked').val() || 'at_period_end';

        // Extra confirmation for immediate cancellation
        if (cancelMode === 'immediately') {
            if (!confirm(i18n.immediateConfirm || 'Are you absolutely sure? Cancelling immediately will revoke your access right now. This cannot be undone.')) {
                return;
            }
        }

        // Disable button to prevent double submission
        $btn.prop('disabled', true).data('processing', true);
        $btn.text(i18n.processing || 'Processing...');

        showLoading(i18n.processing || 'Processing...');

        $.post(ajax, {
            action: 'pillpalnow_cancel_subscription',
            nonce: nonce,
            reason: reason,
            feedback: feedback,
            cancel_mode: cancelMode,
        })
            .done(function (response) {
                hideLoading();
                if (response.success) {
                    // Show success step
                    $('#cancel-step-1').fadeOut(300, function () {
                        $('#cancel-step-2').fadeIn(300);

                        // Update success message based on cancel mode
                        if (response.data.cancel_mode === 'immediately') {
                            $('#pillpalnow-cancel-success-message').text(
                                i18n.cancelledImmediate || 'Your subscription has been cancelled and access has been revoked immediately.'
                            );
                        } else {
                            $('#pillpalnow-cancel-success-message').text(
                                i18n.cancelledGrace || 'Your subscription has been cancelled. You\'ll retain access until the end of your billing period.'
                            );
                        }

                        if (response.data.access_until) {
                            $('#pillpalnow-access-until').text(
                                'Your access continues until: ' + response.data.access_until
                            );
                        }
                    });
                } else {
                    showError(response.data?.message || i18n.error || 'Unable to cancel.');
                    // Re-enable button on error
                    $btn.prop('disabled', false).data('processing', false);
                    $btn.text(i18n.confirmCancel || 'Confirm Cancellation');
                }
            })
            .fail(function () {
                showError(i18n.error || 'Something went wrong. Please try again.');
                // Re-enable button on failure
                $btn.prop('disabled', false).data('processing', false);
                $btn.text(i18n.confirmCancel || 'Confirm Cancellation');
            });
    });

    /**
     * Accept Retention Offer
     */
    $(document).on('click', '#pillpalnow-accept-offer', function (e) {
        e.preventDefault();
        showLoading(i18n.processing || 'Processing...');

        $.post(ajax, {
            action: 'pillpalnow_accept_retention_offer',
            nonce: nonce,
        })
            .done(function (response) {
                hideLoading();
                if (response.success) {
                    // Redirect back to dashboard
                    window.location.href = window.location.pathname;
                } else {
                    showError(response.data?.message || i18n.error);
                }
            })
            .fail(function () {
                showError(i18n.error || 'Something went wrong. Please try again.');
            });
    });

    /**
     * Reactivate Subscription
     */
    $(document).on('click', '#pillpalnow-reactivate', function (e) {
        e.preventDefault();

        const $btn = $(this);

        // Prevent double-click
        if ($btn.prop('disabled') || $btn.data('processing')) {
            return;
        }

        $btn.prop('disabled', true).data('processing', true);
        showLoading(i18n.processing || 'Processing...');

        $.post(ajax, {
            action: 'pillpalnow_reactivate_subscription',
            nonce: nonce,
        })
            .done(function (response) {
                hideLoading();
                if (response.success) {
                    // Reload page to show updated status
                    window.location.reload();
                } else {
                    showError(response.data?.message || i18n.error);
                    $btn.prop('disabled', false).data('processing', false);
                }
            })
            .fail(function () {
                showError(i18n.error || 'Something went wrong. Please try again.');
                $btn.prop('disabled', false).data('processing', false);
            });
    });

    /**
     * Add toast styles dynamically
     */
    const toastCSS = `
        .pillpalnow-toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: #e53e3e;
            color: #fff;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            z-index: 10000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: transform 0.3s ease;
            max-width: 90%;
            text-align: center;
        }
        .pillpalnow-toast.visible {
            transform: translateX(-50%) translateY(0);
        }
    `;
    $('<style>').text(toastCSS).appendTo('head');

})(jQuery);
