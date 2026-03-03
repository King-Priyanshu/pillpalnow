</div> <!-- /container -->
</div> <!-- /app-container -->

<!-- Floating Action Button -->
<a href="<?php echo esc_url(home_url(get_theme_mod('pillpalnow_fab_link', '/add-medication'))); ?>" class="fab">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
    </svg>
</a>

<?php wp_footer(); ?>


<!-- Toast Notification System -->
<!-- Container is already defined in CSS .toast-container -->
<div id="app-toast-container" class="toast-container"></div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const doseAction = urlParams.get('dose_action');
        const medStatus = urlParams.get('med_status_updated');

        function showToast(message, type = 'success') {
            const container = document.getElementById('app-toast-container');
            const toast = document.createElement('div');

            let bgColor = 'var(--success-color)';
            if (type === 'warning') bgColor = 'var(--warning-color)';
            if (type === 'danger') bgColor = 'var(--danger-color)';

            toast.className = `toast show`;
            toast.style.backgroundColor = bgColor;
            if (type === 'warning') toast.style.color = '#000';

            // Icon
            let icon = '';
            if (type === 'success') icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';
            if (type === 'warning') icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';

            toast.innerHTML = `${icon} <span>${message}</span>`;

            container.appendChild(toast);

            // Remove after 3s
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);

            // Clean URL
            const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.replaceState({ path: newUrl }, '', newUrl);
        }

        if (doseAction === 'taken') {
            showToast('✅ You have taken this dose', 'success');
        } else if (doseAction === 'skipped') {
            showToast('⚠️ You skipped this dose', 'warning');
        } else if (medStatus === 'accepted') {
            showToast('✅ Medication accepted', 'success');
        } else if (medStatus === 'rejected') {
            showToast('🚫 Medication rejected', 'success'); // Actually success confirming rejection
        }
    });
</script>

</body>

</html>