document.addEventListener('DOMContentLoaded', () => {

    // Toast Container
    const toastContainer = document.createElement('div');
    toastContainer.className = 'toast-container';
    document.body.appendChild(toastContainer);

    // Toast Function
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        toastContainer.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        // Remove after 3s
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 1500);
    }

    // Action Handlers
    window.handleAction = (action) => {
        // Use localized variable 'pillpalnow_vars' if available, otherwise fallback
        const redirectUrl = (typeof pillpalnow_vars !== 'undefined' && pillpalnow_vars.dashboard_url) ? pillpalnow_vars.dashboard_url : '/';

        switch (action) {
            case 'taken':
                showToast('✅ Medication Taken', 'success');
                setTimeout(() => window.location.href = redirectUrl, 1500);
                break;
            case 'skip':
                showToast('⚠️ Medication Skipped', 'warning');
                setTimeout(() => window.location.href = redirectUrl, 1500);
                break;
            case 'postpone':
                showToast('⏰ Reminder set for 15m', 'info');
                setTimeout(() => window.location.href = redirectUrl, 1500);
                break;
        }
    }

    // --- Dynamic Countdown Timer ---
    // Handle ALL timer elements on the page (hero timer and individual card timers)
    const timerElements = document.querySelectorAll('.hero-timer-text, .card-timer-text');

    timerElements.forEach(timerElement => {
        const targetTs = timerElement.getAttribute('data-target-ts');

        if (targetTs && parseInt(targetTs) > 0) {
            const updateTimer = () => {
                const now = Date.now(); // UTC epoch in ms
                const diff = targetTs - now;

                if (diff <= 0) {
                    timerElement.textContent = "Due Now!";
                    timerElement.classList.add('text-danger');
                    timerElement.classList.remove('text-secondary');
                    return;
                }

                const hours = Math.floor(diff / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

                // Format: "4h 32m"
                timerElement.textContent = `${hours}h ${minutes.toString().padStart(2, '0')}m`;
            };

            // Initial call
            updateTimer();
            // Update every minute (in case time passes)
            setInterval(updateTimer, 60000);
        } else {
            // No valid target timestamp - show "All Done" message
            timerElement.textContent = "✓ All Done";
            timerElement.style.display = "block"; // Ensure visibility
            timerElement.classList.add('text-success');
            timerElement.classList.remove('text-secondary', 'text-danger');
        }
    });
    // --- Double Submit Protection ---
    document.addEventListener('submit', (e) => {
        const form = e.target;

        // Only apply to forms that look like dose actions (contain dosecast nonce or action)
        if (form.querySelector('input[name="pillpalnow_dose_log_nonce"]') || form.action.includes('admin-post.php')) {
            if (form.dataset.submitting === 'true') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }

            form.dataset.submitting = 'true';

            // Visual feedback
            const submitBtn = e.submitter; // The button that triggered the submit
            if (submitBtn) {
                submitBtn.style.opacity = '0.7';
                submitBtn.style.cursor = 'not-allowed';
                // Note: We don't disable attribute to ensure value is sent

                // Add spinner/text if desired
                const originalText = submitBtn.innerText;
                // submitBtn.innerText = 'Processing...'; 
            }

            // Re-enable after timeout (fail-safe)
            setTimeout(() => {
                form.dataset.submitting = 'false';
                if (submitBtn) {
                    submitBtn.style.opacity = '';
                    submitBtn.style.cursor = '';
                    // submitBtn.innerText = originalText;
                }
            }, 5000);
        }
    });

    // --- Check for URL Errors ---
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('error') === 'unauthorized') {
        // Remove the param cleanly without refresh
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);

        // Show error toast
        showToast('You are not authorized to perform this action.', 'error');
    }
});
