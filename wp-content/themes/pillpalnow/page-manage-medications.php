<?php
/**
 * Template Name: Manage Medications
 *
 * @package PillPalNow
 */

// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

get_header('simple');

$current_user_id = get_current_user_id();

// Fetch ALL medications for this user (author)
$meds = get_posts(array(
    'post_type' => 'medication',
    'posts_per_page' => -1,
    'author' => $current_user_id,
    'orderby' => 'title',
    'order' => 'ASC'
));

?>



<!-- Header -->
<header class="app-header border-b border-gray-800">
    <div class="container flex items-center justify-between h-full">
        <a href="<?php echo home_url('/dashboard'); ?>" class="flex items-center text-secondary">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5"></path>
                <path d="M12 19l-7-7 7-7"></path>
            </svg>
            <span class="ml-2">Dashboard</span>
        </a>
        <h1 class="text-lg font-bold">Manage Medications</h1>
        <div style="width: 24px;"></div>
    </div>
</header>

<main class="p-4 pb-24">

    <div class="flex justify-between items-center mb-6">
        <h2 class="text-xl font-bold">Saved Medications</h2>
        <?php if (class_exists('PillPalNow_Permissions') && PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_ADD_MEDICATION, true)): ?>
            <a href="<?php echo home_url('/add-medication'); ?>" class="btn btn-sm btn-primary w-auto px-6">+ Add New</a>
        <?php endif; ?>
    </div>

    <?php if (empty($meds)): ?>
        <div class="dashboard-card p-8 text-center">
            <div class="text-4xl mb-4">💊</div>
            <h3 class="text-lg font-semibold mb-2">No medications found</h3>
            <p class="text-secondary mb-4">Add your first medication to get started.</p>
            <?php if (class_exists('PillPalNow_Permissions') && PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_ADD_MEDICATION, true)): ?>
                <a href="<?php echo home_url('/add-medication'); ?>" class="btn btn-primary w-auto px-8">Add Medication</a>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <div class="overflow-x-auto rounded-lg border border-gray-800">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-gray-800 text-gray-400 uppercase text-xs tracking-wider">
                    <tr>
                        <th class="p-4 font-medium text-left" style="width: 30%;">Medication</th>
                        <th class="p-4 font-medium text-left" style="width: 15%;">Dosage</th>
                        <th class="p-4 font-medium text-left" style="width: 15%;">Schedule</th>
                        <th class="p-4 font-medium text-left" style="width: 20%;">Member</th>
                        <th class="p-4 font-medium text-right" style="width: 20%;">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800 bg-gray-900/50">
                    <?php foreach ($meds as $med):
                        $schedule_type = get_post_meta($med->ID, 'schedule_type', true);
                        $dosage = get_post_meta($med->ID, 'dosage', true);
                        $dose_times = get_post_meta($med->ID, 'dose_times', true);
                        $assigned_user_id = get_post_meta($med->ID, 'assigned_user_id', true);
                        $member_id = get_post_meta($med->ID, 'family_member_id', true);
                        $member_name = 'Unknown';

                        if (!empty($assigned_user_id) && $assigned_user_id == $current_user_id) {
                            $member_name = 'Self';
                        } elseif ($member_id) {
                            $member_name = get_the_title($member_id);
                        } else {
                            // Fallback to old text field or assigned_to
                            $member_name = get_post_meta($med->ID, 'assigned_to', true) ?: '-';
                        }

                        // Build dosage display from dose_times
                        $dosage_display = $dosage ?: '—';
                        $times_display = '';
                        if (is_array($dose_times) && !empty($dose_times)) {
                            $time_parts = array();
                            $dosage_parts = array();
                            foreach ($dose_times as $dt) {
                                $time_val = isset($dt['time']) ? date('g:i A', strtotime($dt['time'])) : '';
                                $dose_val = isset($dt['dosage']) ? $dt['dosage'] : '1';
                                if ($time_val) {
                                    $time_parts[] = $time_val;
                                }
                                $dosage_parts[] = $dose_val . ' pill';
                            }
                            if (count($dosage_parts) > 1) {
                                // Multiple doses - show count
                                $dosage_display = count($dosage_parts) . ' doses/day';
                            } else {
                                $dosage_display = $dosage_parts[0] ?? $dosage;
                            }
                            $times_display = implode(', ', $time_parts);
                        }

                        $detail_url = get_permalink($med->ID);
                        $edit_url = add_query_arg(array(
                            'med_id' => $med->ID,
                            'redirect_to' => home_url('/manage-medications')
                        ), home_url('/edit-medication/'));

                        $delete_url = admin_url('admin-post.php');
                        $delete_url = add_query_arg(array(
                            'action' => 'pillpalnow_delete_medication',
                            'med_id' => $med->ID,
                            '_wpnonce' => wp_create_nonce('pillpalnow_delete_med_' . $med->ID),
                            'redirect_to' => home_url('/manage-medications')
                        ), $delete_url);
                        ?>
                        <tr class="hover:bg-gray-800/50 transition-colors group">
                            <td class="p-4 font-medium text-white align-middle">
                                <?php echo esc_html($med->post_title); ?>
                            </td>
                            <td class="p-4 text-gray-300 align-middle">
                                <div>
                                    <span class="font-medium"><?php echo esc_html($dosage_display); ?></span>
                                    <?php if ($times_display): ?>
                                        <div class="text-xs text-gray-500 mt-1"><?php echo esc_html($times_display); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="p-4 text-gray-300 capitalize align-middle">
                                <?php echo esc_html($schedule_type); ?>
                            </td>
                            <td class="p-4 text-gray-300 align-middle">
                                <span
                                    class="px-2 py-1 rounded bg-gray-800 text-xs text-gray-300 border border-gray-700 inline-block">
                                    <?php echo esc_html($member_name); ?>
                                </span>
                            </td>
                            <td class="p-4 text-right align-middle">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if (class_exists('PillPalNow_Permissions') && PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_EDIT_MEDICATION, true)): ?>
                                        <a href="<?php echo esc_url($edit_url); ?>"
                                            class="p-2 text-blue-400 hover:bg-blue-900/20 rounded transition-colors" title="Edit">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (class_exists('PillPalNow_Permissions') && PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_DELETE_MEDICATION, true)): ?>
                                        <a href="<?php echo esc_url($delete_url); ?>"
                                            class="p-2 hover:bg-red-900/20 rounded transition-colors delete-medication-btn"
                                            title="Delete Medication"
                                            style="color: #f87171; display: inline-flex; align-items: center; justify-content: center; padding: 8px;"
                                            onclick="event.preventDefault(); if(confirm('Are you sure you want to delete this medication? This action cannot be undone.')) { window.location.href = this.href; }">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="3 6 5 6 21 6"></polyline>
                                                <path
                                                    d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                </path>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

</main>



<?php get_footer(); ?>