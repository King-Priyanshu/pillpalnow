jQuery(document).ready(function ($) {
    $('.stripe-saas-subscribe-btn').on('click', function (e) {
        e.preventDefault();

        const $btn = $(this);
        let $wrapper, $error;

        // Find error container based on context
        if ($btn.closest('#stripe-saas-dashboard-container').length > 0) {
            // In dashboard
            $wrapper = $('#stripe-saas-dashboard-container');
            // Create error container if not exists
            $error = $wrapper.find('.stripe-saas-error');
            if ($error.length === 0) {
                $error = $('<div class="stripe-saas-error" style="display:none;"></div>');
                $wrapper.append($error);
            }
        } else {
            // In choose plan shortcode
            $wrapper = $btn.closest('.stripe-saas-subscribe-wrapper');
            $error = $wrapper.find('.stripe-saas-error');
        }

        const tier = $btn.data('tier');
        const originalText = $btn.text();
        const loadingText = $btn.data('loading-text') || 'Processing...';

        // Disable button and show loading
        $btn.prop('disabled', true).text(loadingText);
        $error.hide().text('');

        // Send AJAX request
        $.post(stripeSaasData.ajaxUrl, {
            action: 'stripe_saas_create_session',
            nonce: stripeSaasData.nonce,
            tier: tier
        })
            .done(function (response) {
                if (response.success) {
                    if (response.data.type === 'direct_update') {
                        // Show success message and reload
                        $btn.text('Success!');
                        $error.removeClass('stripe-saas-error').addClass('stripe-saas-success')
                            .text(response.data.message).show();

                        setTimeout(function () {
                            window.location.reload();
                        }, 2000);
                    } else if (response.data.url) {
                        // Redirect to Stripe Checkout
                        window.location.href = response.data.url;
                    }
                } else {
                    // Show error
                    $error.text(response.data.message || 'An error occurred').show();
                    $btn.prop('disabled', false).text(originalText);
                }
            })
            .fail(function (xhr) {
                // Network error
                let errorMsg = 'Network error. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                $error.text(errorMsg).show();
                $btn.prop('disabled', false).text(originalText);
            });
    });
});
