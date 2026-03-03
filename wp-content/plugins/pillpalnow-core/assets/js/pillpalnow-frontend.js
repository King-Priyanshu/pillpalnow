/**
 * PillPalNow Frontend Scripts
 */
document.addEventListener('DOMContentLoaded', function () {

    // --- Share History Logic ---
    const shareBtn = document.getElementById('pillpalnow-share-history-btn');
    const shareModal = document.getElementById('pillpalnow-share-modal');

    if (shareBtn && shareModal) {

        // Modal Controls
        const closeBtn = shareModal.querySelector('.modal-close');
        const cancelBtn = shareModal.querySelector('.btn-cancel');
        const sendBtn = document.getElementById('pillpalnow-share-submit');
        const emailInput = document.getElementById('pillpalnow-share-emails');
        const msgContainer = document.getElementById('pillpalnow-share-message');

        const openModal = () => {
            shareModal.classList.remove('hidden');
            shareModal.style.display = 'flex'; // Just in case class hidden uses display:none
            msgContainer.innerHTML = '';
            emailInput.value = '';
        };

        const closeModal = () => {
            shareModal.classList.add('hidden');
            shareModal.style.display = 'none';
        };

        shareBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openModal();
        });

        [closeBtn, cancelBtn].forEach(btn => {
            if (btn) btn.addEventListener('click', closeModal);
        });

        // Close on click outside
        window.addEventListener('click', (e) => {
            if (e.target === shareModal) {
                closeModal();
            }
        });

        // Submit AJAX
        if (sendBtn) {
            sendBtn.addEventListener('click', function (e) {
                e.preventDefault();

                const emails = emailInput.value.trim();
                const memberId = document.getElementById('pillpalnow_share_member_id').value;
                const month = document.getElementById('pillpalnow_share_month').value;

                if (!emails) {
                    msgContainer.innerHTML = '<span class="text-red-500">Please enter an email address.</span>';
                    return;
                }

                // UI Loading State
                const originalText = sendBtn.innerText;
                sendBtn.innerText = 'Sending...';
                sendBtn.disabled = true;
                msgContainer.innerHTML = '';

                const formData = new FormData();
                formData.append('action', 'pillpalnow_share_history');
                formData.append('emails', emails);
                formData.append('member_id', memberId);
                formData.append('month', month);
                formData.append('nonce', pillpalnow_share_vars.share_nonce);

                fetch(pillpalnow_share_vars.ajaxurl, {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        sendBtn.innerText = originalText;
                        sendBtn.disabled = false;

                        if (data.success) {
                            msgContainer.innerHTML = `<span class="text-green-500">${data.data.message}</span>`;
                            // Optional: close modal after delay
                            setTimeout(closeModal, 2000);
                        } else {
                            msgContainer.innerHTML = `<span class="text-red-500">${data.data.message || 'Error occurred.'}</span>`;
                        }
                    })
                    .catch(error => {
                        sendBtn.innerText = originalText;
                        sendBtn.disabled = false;
                        console.error('Error:', error);
                        msgContainer.innerHTML = '<span class="text-red-500">Network error. Please try again.</span>';
                    });
            });
        }
    }

});
