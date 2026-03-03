document.addEventListener('DOMContentLoaded', function () {
    // Only initialize if the container exists
    const paymentElementContainer = document.getElementById('pillpalnow-payment-element');

    // We might need to listen for the form even if container is dynamic, 
    // but typically the container is present on the checkout page.
    if (!paymentElementContainer) {
        return;
    }

    // --- Retry Logic for Stripe.js (WebView Fix) ---
    let stripeCheckAttempts = 0;
    const maxAttempts = 20; // 10 seconds total

    // --- Post-Payment Sync Logic ---
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('payment_status') === 'success') {
        // Clear param to prevent loop (optional, or just handle it)
        // window.history.replaceState({}, document.title, window.location.pathname);

        // Force Sync
        if (typeof pillpalnowStripeData !== 'undefined' && pillpalnowStripeData.restUrl) {
            fetch(pillpalnowStripeData.restUrl + 'check-subscription', {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': pillpalnowStripeData.restNonce
                }
            })
            .then(res => res.json())
            .then(data => {
                console.log('Subscription Sync:', data);
                if (data.status === 'ACTIVE' || data.status === 'TRIAL') {
                    // Update UI if needed, or redirect to dashboard/profile
                    const msg = document.getElementById('payment-message');
                    if (msg) {
                        msg.textContent = 'Subscription Activated! Reloading...';
                        msg.className = 'payment-message success';
                    }
                    setTimeout(() => {
                        window.location.reload(); // Refresh to reflect new status
                    }, 2000);
                }
            })
            .catch(err => console.error('Sync failed:', err));
        }
    }

    function checkAndInitStripe() {
        if (typeof Stripe !== 'undefined') {
            initializeStripeFlow();
        } else {
            stripeCheckAttempts++;
            if (stripeCheckAttempts < maxAttempts) {
                // console.log('Waiting for Stripe.js...', stripeCheckAttempts);
                setTimeout(checkAndInitStripe, 500);
            } else {
                console.error('Stripe.js failed to load after multiple attempts');
                showMessage('Payment system failed to load. Please refresh.', 'error');
            }
        }
    }

    // Start checking
    checkAndInitStripe();

    function initializeStripeFlow() {
        if (!pillpalnowStripeData || !pillpalnowStripeData.publishableKey) {
            console.error('PillPalNow Stripe configuration missing');
            return;
        }

        const stripe = Stripe(pillpalnowStripeData.publishableKey);
        setupPaymentLogic(stripe);
    }

    function setupPaymentLogic(stripe) {
        let elements;
        let paymentElement;

        // Function to initialize payment element
        async function initializePayment(amount, currency = 'usd') {
            try {
                const response = await fetch(pillpalnowStripeData.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'pillpalnow_create_payment_intent',
                        nonce: pillpalnowStripeData.nonce,
                        amount: amount,
                        currency: currency
                    })
                });

                const data = await response.json();

                if (!data.success) {
                    showMessage(data.data.message || 'Error initializing payment', 'error');
                    return;
                }

                const clientSecret = data.data.clientSecret;

                const appearance = { theme: 'stripe' };
                elements = stripe.elements({ appearance, clientSecret });

                const paymentElementOptions = { layout: 'tabs' };
                paymentElement = elements.create('payment', paymentElementOptions);
                paymentElement.mount('#pillpalnow-payment-element');

                // Unhide the form/container once loaded
                paymentElementContainer.classList.remove('hidden');

            } catch (error) {
                console.error('Error:', error);
                showMessage('An unexpected error occurred.', 'error');
            }
        }

        // Handle Form Submission
        const form = document.getElementById('pillpalnow-payment-form');
        if (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                setLoading(true);

                if (!stripe || !elements) {
                    return;
                }

                const { error } = await stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        // Make sure to change this to your payment completion page
                        return_url: window.location.href + '?payment_status=success',
                    },
                });

                if (error) {
                    showMessage(error.message, 'error');
                    setLoading(false);
                } else {
                    // The UI automatically redirects to return_url
                }
            });
        }

        // Expose init function globally if needed for dynamic forms
        window.pillpalnowInitPayment = initializePayment;
    }

    // Helper: Show messages
    function showMessage(messageText, type = 'info') {
        const messageContainer = document.getElementById('payment-message');
        if (messageContainer) {
            messageContainer.textContent = messageText;
            messageContainer.className = 'payment-message ' + type;
            setTimeout(() => {
                messageContainer.textContent = '';
                messageContainer.className = 'payment-message';
            }, 5000);
        }
    }

    // Helper: Loading State
    function setLoading(isLoading) {
        const submitBtn = document.getElementById('submit-payment');
        if (submitBtn) {
            submitBtn.disabled = isLoading;
            submitBtn.textContent = isLoading ? 'Processing...' : 'Pay Now';
        }
    }
});
