<?php
/**
 * Template Name: Profile Page
 *
 * @package PillPalNow
 */

// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
get_header();
?>

<div class="app-container flex-col justify-between" style="min-height: 100vh;">

    <div class="container flex-1">

        <!-- Header -->
        <header class="app-header">
            <h1 class="text-xl font-bold"><?php esc_html_e('My Profile', 'pillpalnow'); ?></h1>
            <a href="<?php echo wp_logout_url(home_url('/login')); ?>"
                class="btn btn-text text-sm p-0 text-danger"><?php esc_html_e('Log Out', 'pillpalnow'); ?></a>
        </header>

        <main class="flex flex-col gap-6 pb-24">

            <!-- Profile Card -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                class="card p-6 flex flex-col gap-4">
                <input type="hidden" name="action" value="pillpalnow_update_profile">
                <?php wp_nonce_field('pillpalnow_profile_action', 'pillpalnow_profile_nonce'); ?>

                <div class="flex items-center gap-4 mb-2">
                    <div
                        class="w-16 h-16 bg-primary rounded-full flex items-center justify-center text-3xl font-bold text-white shadow-lg shadow-blue-500/20 relative">
                        <?php echo strtoupper(substr($current_user->display_name, 0, 1)); ?>
                        <?php if (class_exists('Subscription_Manager') && Subscription_Manager::is_pro_user()): ?>
                            <div
                                class="absolute -top-1 -right-1 bg-yellow-400 text-black text-[10px] font-bold px-2 py-0.5 rounded-full border border-white">
                                PRO</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="font-bold text-xl"><?php echo esc_html($current_user->display_name); ?></h2>
                        <?php if (class_exists('Subscription_Manager') && Subscription_Manager::is_pro_user()): ?>
                            <p class="text-yellow-400 text-sm font-bold flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Pro Plan Active
                            </p>
                        <?php else: ?>
                            <p class="text-secondary text-sm"><?php esc_html_e('Free Plan', 'pillpalnow'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($_GET['updated']) && $_GET['updated'] == 'true'): ?>
                    <div class="text-success text-sm"><?php esc_html_e('Profile updated successfully.', 'pillpalnow'); ?>
                    </div>
                <?php endif; ?>

                <div class="grid gap-4 mt-2">
                    <div>
                        <label
                            class="block text-sm font-semibold text-secondary mb-1"><?php esc_html_e('Display Name', 'pillpalnow'); ?></label>
                        <input type="text" name="display_name" class="input-field w-full"
                            value="<?php echo esc_attr($current_user->display_name); ?>">
                    </div>
                    <div>
                        <label
                            class="block text-sm font-semibold text-secondary mb-1"><?php esc_html_e('Email', 'pillpalnow'); ?></label>
                        <input type="email" name="email" class="input-field w-full"
                            value="<?php echo esc_attr($current_user->user_email); ?>">
                    </div>
                    <div>
                        <label
                            class="block text-sm font-semibold text-secondary mb-1"><?php esc_html_e('New Password (leave blank to keep)', 'pillpalnow'); ?></label>
                        <input type="password" name="pass1" class="input-field w-full">
                    </div>
                    <div>
                        <label
                            class="block text-sm font-semibold text-secondary mb-1"><?php esc_html_e('Confirm New Password', 'pillpalnow'); ?></label>
                        <input type="password" name="pass2" class="input-field w-full">
                    </div>
                </div>

                <button type="submit"
                    class="btn btn-primary text-sm py-2 mt-2"><?php esc_html_e('Save Changes', 'pillpalnow'); ?></button>
            </form>

            <!-- Family Members (Managed via Dashboard/Add Family Member) -->
            <div class="hidden">
                <!-- Family management moved to dedicated page -->
            </div>

            <!-- App Settings -->
            <div>
                <h3 class="text-sm font-semibold text-secondary uppercase tracking-wider mb-3">
                    <?php esc_html_e('Subscription', 'pillpalnow'); ?>
                </h3>

                <?php if (class_exists('Subscription_Manager') && Subscription_Manager::is_pro_user()):
                    $expiry = get_user_meta($current_user->ID, Subscription_Manager::META_KEY_EXPIRY_DATE, true);
                    $expiry_date = $expiry ? date('F j, Y', $expiry) : 'Unlimited';
                    // Check if cancelling
                    $is_canceling = get_user_meta($current_user->ID, 'pillpalnow_cancel_at_period_end', true);
                    ?>
                    <div class="card p-4 border border-green-800 bg-green-900/10">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="font-bold text-lg text-white">PillPalNow Pro</h4>
                            <?php if ($is_canceling): ?>
                                <span class="bg-yellow-100 text-yellow-800 text-xs font-bold px-2 py-1 rounded">CANCELING</span>
                            <?php else: ?>
                                <span class="bg-green-100 text-green-800 text-xs font-bold px-2 py-1 rounded">ACTIVE</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-gray-400 text-sm mb-4">You have full access to Cloud Sync, Family Sharing, and
                            Reports.</p>

                        <?php if ($is_canceling): ?>
                            <p class="text-xs text-yellow-500 mb-2">Your subscription will end on:
                                <?php echo esc_html($expiry_date); ?></p>
                        <?php else: ?>
                            <p class="text-xs text-gray-500 mb-4">Renews on: <?php echo esc_html($expiry_date); ?></p>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                onsubmit="return confirm('Are you sure you want to cancel your subscription? You will retain access until the end of the current billing period.');">
                                <input type="hidden" name="action" value="pillpalnow_cancel_subscription">
                                <?php wp_nonce_field('pillpalnow_cancel_subscription', 'pillpalnow_cancel_nonce'); ?>
                                <button type="submit"
                                    class="text-xs text-red-400 underline hover:text-red-300 bg-transparent border-0 p-0 cursor-pointer">
                                    Cancel Subscription
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="card p-4 bg-gradient-to-r from-blue-900 to-blue-800 border-none relative overflow-hidden">
                        <!-- Decorative circle -->
                        <div class="absolute -right-10 -top-10 w-32 h-32 bg-primary blur-3xl opacity-30 rounded-full"></div>

                        <div class="relative z-10">
                            <h4 class="font-bold text-lg text-white mb-1">PillPalNow Pro</h4>
                            <p class="text-blue-200 text-sm mb-4">Unlock unlimited family members, cloud sync, and PDF
                                reports.</p>
                            <a href="<?php echo home_url('/upgrade'); ?>"
                                class="btn bg-white text-blue-900 font-bold py-2 px-4 rounded-lg text-sm w-full text-center block">
                                Upgrade for $2.99/mo
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Notification Management -->
            <div id="notification-management">
                <h3 class="text-sm font-semibold text-secondary uppercase tracking-wider mb-3">
                    <?php esc_html_e('Notification Settings', 'pillpalnow'); ?>
                </h3>

                <!-- Preferences -->
                <div class="card p-6 mb-6">
                    <h4 class="font-bold text-lg mb-4"><?php esc_html_e('Notification Preferences', 'pillpalnow'); ?></h4>
                    <form id="notification-preferences-form" class="space-y-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="pref-reminders" name="reminders" class="mr-3">
                            <label for="pref-reminders"
                                class="text-sm"><?php esc_html_e('Medication Reminders', 'pillpalnow'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="pref-refills" name="refills" class="mr-3">
                            <label for="pref-refills"
                                class="text-sm"><?php esc_html_e('Refill Alerts', 'pillpalnow'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="pref-missed" name="missed" class="mr-3">
                            <label for="pref-missed"
                                class="text-sm"><?php esc_html_e('Missed Dose Notifications', 'pillpalnow'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="pref-family" name="family" class="mr-3">
                            <label for="pref-family"
                                class="text-sm"><?php esc_html_e('Family Member Activity', 'pillpalnow'); ?></label>
                        </div>
                        <button type="submit"
                            class="btn btn-primary text-sm py-2"><?php esc_html_e('Save Preferences', 'pillpalnow'); ?></button>
                    </form>
                </div>

                <!-- History -->
                <div class="card p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="font-bold text-lg"><?php esc_html_e('Notification History', 'pillpalnow'); ?></h4>
                        <div class="flex gap-2">
                            <button id="mark-selected-read"
                                class="btn btn-secondary text-sm py-1 px-3"><?php esc_html_e('Mark Read', 'pillpalnow'); ?></button>
                            <button id="delete-selected"
                                class="btn btn-danger text-sm py-1 px-3"><?php esc_html_e('Delete', 'pillpalnow'); ?></button>
                        </div>
                    </div>

                    <div id="notifications-list" class="space-y-2">
                        <!-- Notifications will be loaded here -->
                    </div>

                    <div id="pagination" class="flex justify-center mt-4">
                        <!-- Pagination will be loaded here -->
                    </div>
                </div>
            </div>

        </main>
    </div>

</div>

<script>
    jQuery(document).ready(function ($) {
        const restUrl = pillpalnowProfile ? pillpalnowProfile.restUrl : '/wp-json/pillpalnow/v1/';
        const nonce = pillpalnowProfile ? pillpalnowProfile.nonce : '';

        let currentPage = 1;
        const perPage = 20;

        // Load preferences
        function loadPreferences() {
            $.ajax({
                url: restUrl + 'notifications/preferences',
                method: 'GET',
                headers: { 'X-WP-Nonce': nonce }
            })
                .done(function (response) {
                    if (response.success) {
                        $('#pref-reminders').prop('checked', response.preferences.reminders);
                        $('#pref-refills').prop('checked', response.preferences.refills);
                        $('#pref-missed').prop('checked', response.preferences.missed);
                        $('#pref-family').prop('checked', response.preferences.family);
                    }
                });
        }

        // Save preferences
        $('#notification-preferences-form').on('submit', function (e) {
            e.preventDefault();
            const preferences = {
                reminders: $('#pref-reminders').is(':checked'),
                refills: $('#pref-refills').is(':checked'),
                missed: $('#pref-missed').is(':checked'),
                family: $('#pref-family').is(':checked')
            };

            $.ajax({
                url: restUrl + 'notifications/preferences',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
                data: { preferences: preferences }
            })
                .done(function (response) {
                    if (response.success) {
                        alert('Preferences saved successfully!');
                    } else {
                        alert('Failed to save preferences.');
                    }
                });
        });

        // Load notifications
        function loadNotifications(page = 1) {
            currentPage = page;
            $.ajax({
                url: restUrl + 'notifications',
                method: 'GET',
                headers: { 'X-WP-Nonce': nonce },
                data: {
                    limit: perPage,
                    offset: (page - 1) * perPage
                }
            })
                .done(function (response) {
                    if (response.success) {
                        renderNotifications(response.notifications);
                        renderPagination(response.total, page);
                    }
                });
        }

        // Render notifications
        function renderNotifications(notifications) {
            const $list = $('#notifications-list');
            $list.empty();

            if (notifications.length === 0) {
                $list.append('<p class="text-gray-500 text-center py-4">No notifications found.</p>');
                return;
            }

            notifications.forEach(function (notif) {
                const statusClass = notif.status === 'unread' ? 'bg-blue-900/20 border-blue-800' : 'bg-gray-800 border-gray-700';
                const statusText = notif.status === 'unread' ? 'Unread' : 'Read';
                const statusColor = notif.status === 'unread' ? 'text-blue-400' : 'text-gray-400';

                const $item = $(`
                <div class="notification-item border rounded p-3 ${statusClass}" data-id="${notif.id}">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" class="notification-checkbox mt-1">
                        <div class="flex-1">
                            <div class="flex justify-between items-start">
                                <h5 class="font-semibold text-sm">${notif.title}</h5>
                                <span class="text-xs ${statusColor}">${statusText}</span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1">${notif.message}</p>
                            <p class="text-xs text-gray-400 mt-1">${new Date(notif.created_at).toLocaleDateString()}</p>
                        </div>
                    </div>
                </div>
            `);
                $list.append($item);
            });
        }

        // Render pagination
        function renderPagination(total, currentPage) {
            const $pagination = $('#pagination');
            $pagination.empty();

            const totalPages = Math.ceil(total / perPage);

            if (totalPages <= 1) return;

            let html = '<div class="flex gap-2">';

            for (let i = 1; i <= totalPages; i++) {
                const activeClass = i === currentPage ? 'bg-primary text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600';
                html += `<button class="pagination-btn px-3 py-1 rounded text-sm ${activeClass}" data-page="${i}">${i}</button>`;
            }

            html += '</div>';
            $pagination.html(html);
        }

        // Pagination click
        $(document).on('click', '.pagination-btn', function () {
            const page = $(this).data('page');
            loadNotifications(page);
        });

        // Bulk mark as read
        $('#mark-selected-read').on('click', function () {
            const selectedIds = $('.notification-checkbox:checked').map(function () {
                return $(this).closest('.notification-item').data('id');
            }).get();

            if (selectedIds.length === 0) {
                alert('Please select notifications to mark as read.');
                return;
            }

            $.ajax({
                url: restUrl + 'notifications/mark-read',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
                data: { notification_ids: selectedIds }
            })
                .done(function (response) {
                    if (response.success) {
                        loadNotifications(currentPage);
                    } else {
                        alert('Failed to mark notifications as read.');
                    }
                });
        });

        // Bulk delete
        $('#delete-selected').on('click', function () {
            const selectedIds = $('.notification-checkbox:checked').map(function () {
                return $(this).closest('.notification-item').data('id');
            }).get();

            if (selectedIds.length === 0) {
                alert('Please select notifications to delete.');
                return;
            }

            if (!confirm('Are you sure you want to delete the selected notifications?')) return;

            $.ajax({
                url: restUrl + 'notifications/delete',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
                data: { notification_ids: selectedIds }
            })
                .done(function (response) {
                    if (response.success) {
                        loadNotifications(currentPage);
                    } else {
                        alert('Failed to delete notifications.');
                    }
                });
        });

        // Initialize
        loadPreferences();
        loadNotifications();
    });
</script>

<?php get_footer(); ?>