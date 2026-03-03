jQuery(document).ready(function ($) {
    const apiRoot = stripeSaasDashboard.root;
    const nonce = stripeSaasDashboard.nonce;

    function formatCurrency(amount) {
        return amount; // Already formatted from backend
    }

    function loadDashboard() {
        $.ajax({
            url: apiRoot + '/subscription',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function (response) {
                renderDashboard(response);
            },
            error: function (err) {
                console.error(err);
                let message = stripeSaasDashboard.i18n.error_generic;
                if (err.responseJSON && err.responseJSON.message) {
                    message += '<br><strong>Error Details:</strong> ' + err.responseJSON.message;
                } else if (err.status) {
                    message += '<br><strong>HTTP ' + err.status + ':</strong> ' + err.statusText;
                    if (err.status === 404) {
                        message += '<br><small>HINT: REST API endpoint not found. Try going to WP Admin -> Settings -> Permalinks and clicking "Save Changes" to flush rewrite rules on your live server.</small>';
                    }
                }
                $('.stripe-saas-loading').html('<p style="color:red;">' + message + '</p>');
            }
        });
    }

    function renderDashboard(data) {
        // Inject Header if not present
        if ($('#stripe-saas-dashboard-container .dashboard-header').length === 0) {
            $('#stripe-saas-dashboard-container').prepend(`
                <div class="dashboard-header">
                    <h2>Subscription Management</h2>
                </div>
            `);
        }

        // Hide loading, show content
        $('.stripe-saas-loading').hide();
        $('#stripe-saas-dashboard-content').fadeIn();

        // Handle WP_Error returned with 200 OK status
        if (data.code && data.message) {
            $('.stripe-saas-loading').hide();
            $('#stripe-saas-dashboard-content').fadeIn();
            $('#saas-plan-name').text('No Plan Selected');
            $('#saas-plan-status').text('Inactive');
            $('#saas-next-date').text('--');
            $('#saas-plan-amount').text('--');
            $('#btn-cancel-subscription').hide();
            $('#btn-reactivate-subscription').hide();
            if ($('#saas-upgrade-section').length > 0) {
                $('#saas-upgrade-section').remove();
            }
            $('#saas-billing-history-list').html('<li>' + data.message + '</li>');
            return;
        }

        // 1. Current Plan Details
        $('#saas-plan-name').text(data.plan_name);
        $('#saas-plan-status').text(data.status);
        $('#saas-next-date').text(data.next_payment_date);

        // Fix for "Nothing Paid" or empty amount
        let amountText = data.amount ? data.amount.toString() : '';
        if (amountText.includes('0.00')) {
            amountText = amountText + ' (Trial)';
        }
        $('#saas-plan-amount').text(amountText || '--');

        // Styling status
        if (data.cancel_at_period_end) {
            $('#saas-plan-status').addClass('text-warning');
            $('#btn-cancel-subscription').hide();
            $('#btn-reactivate-subscription').show();
        } else if (data.status_raw === 'active' || data.status_raw === 'trialing') {
            $('#saas-plan-status').addClass('text-success');
            $('#btn-cancel-subscription').show();
            $('#btn-reactivate-subscription').hide();
        }

        // Remove existing plans section if it exists
        if ($('#saas-upgrade-section').length > 0) {
            $('#saas-upgrade-section').remove();
        }

        // 3. Billing History
        const historyList = $('#saas-billing-history-list');
        historyList.empty();

        if (data.invoices && data.invoices.length > 0) {
            data.invoices.forEach(function (inv) {
                // Fix "Nothing Paid" - check status
                let statusBadge = inv.status;
                if (inv.status === 'paid') statusBadge = '<span class="text-success">Paid</span>';

                historyList.append(`
                    <li>
                        <span>
                            <strong>${inv.date}</strong> - ${inv.amount}
                            <br><small>${statusBadge}</small>
                        </span>
                        <a href="${inv.pdf_url}" target="_blank" class="download-invoice">Download PDF</a>
                    </li>
                `);
            });
        } else {
            historyList.append('<li>' + (stripeSaasDashboard.i18n.no_invoices || 'No billing history available.') + '</li>');
        }

        // 4. Bind Change Plan Buttons
        $('.btn-change-plan').off('click').on('click', function () {
            let tierSlug = $(this).data('slug');
            changePlan(tierSlug);
        });
    }

    function changePlan(tierSlug) {
        if (!confirm('Are you sure you want to change your plan? You will be charged for any difference immediately.')) {
            return;
        }

        // Show loading state on button
        let $btn = $(`.btn-change-plan[data-slug="${tierSlug}"]`);
        let originalText = $btn.text();
        $btn.text('Updating...').prop('disabled', true);

        $.ajax({
            url: apiRoot + '/subscription/change',
            method: 'POST',
            data: { tier_slug: tierSlug },
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function (response) {
                alert(response.message);
                loadDashboard(); // Refresh dashboard to show new current plan
            },
            error: function (xhr) {
                let msg = stripeSaasDashboard.i18n.error_generic;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                alert('Error: ' + msg);
                $btn.text(originalText).prop('disabled', false);
            }
        });
    }

    // Modal Logic
    const modal = $('#saas-cancel-modal');
    const closeBtn = $('.close-modal');
    const abortBtn = $('#btn-abort-cancel');
    const confirmBtn = $('#btn-confirm-cancel');

    $('#btn-cancel-subscription').off('click').click(function () {
        // Update "Access until" date in modal
        let nextDate = $('#saas-next-date').text();
        $('#modal-access-until').text(nextDate);
        modal.fadeIn();
    });

    closeBtn.click(() => modal.fadeOut());
    abortBtn.click(() => modal.fadeOut());

    $(window).click(function (event) {
        if ($(event.target).is(modal)) {
            modal.fadeOut();
        }
    });

    confirmBtn.off('click').click(function () {
        $(this).text('Processing...').prop('disabled', true);

        $.ajax({
            url: apiRoot + '/subscription/cancel',
            method: 'POST',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function (response) {
                alert(response.message);
                modal.fadeOut();
                loadDashboard(); // Reload to show status
            },
            error: function (err) {
                alert(stripeSaasDashboard.i18n.error_generic);
                confirmBtn.text('Confirm Cancellation').prop('disabled', false);
            }
        });
    });

    // Reactivate Logic
    $('#btn-reactivate-subscription').off('click').click(function () {
        let $btn = $(this);
        $btn.text('Resuming...').prop('disabled', true);

        $.ajax({
            url: apiRoot + '/subscription/reactivate',
            method: 'POST',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function (response) {
                alert(response.message);
                $btn.text('Resume Subscription').prop('disabled', false);
                loadDashboard();
            },
            error: function (xhr) {
                alert(stripeSaasDashboard.i18n.error_generic);
                $btn.text('Resume Subscription').prop('disabled', false);
            }
        });
    });

    // Portal Session
    $('#btn-update-payment').off('click').click(function () {
        $(this).text('Redirecting...').prop('disabled', true);

        $.ajax({
            url: apiRoot + '/portal-session',
            method: 'POST',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function (response) {
                window.location.href = response.url;
            },
            error: function (err) {
                alert(stripeSaasDashboard.i18n.error_generic);
                $('#btn-update-payment').text('Update Payment Method').prop('disabled', false);
            }
        });
    });

    // Init
    loadDashboard();
});
