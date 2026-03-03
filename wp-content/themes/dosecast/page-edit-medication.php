<?php
/**
 * Template Name: Edit Medication
 *
 * @package PillPalNow
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$med_id = isset($_GET['med_id']) ? intval($_GET['med_id']) : 0;
$med = get_post($med_id);

// Security Check - STRICT ISOLATION: Only assigned user can edit
// Security Check - Allow assigned user OR creator (parent)
$assigned_user_id = (int) get_post_meta($med_id, 'assigned_user_id', true);
$is_creator = ((int) $med->post_author === get_current_user_id());

if (!$med || $med->post_type !== 'medication' || ($assigned_user_id !== get_current_user_id() && !$is_creator)) {
    wp_die('Unauthorized: You can only edit medications assigned to you or your family members.');
}

// PERMISSION CHECK
if (class_exists('PillPalNow_Permissions') && !PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_EDIT_MEDICATION, true)) {
    wp_die('You do not have permission to edit medications.', 'Permission Denied', array('response' => 403));
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pillpalnow_edit_medication') {
    if (!isset($_POST['pillpalnow_edit_nonce']) || !wp_verify_nonce($_POST['pillpalnow_edit_nonce'], 'pillpalnow_edit_action')) {
        wp_die('Security check failed');
    }

    $title = sanitize_text_field($_POST['post_title']);
    $stock_quantity = intval($_POST['stock_quantity']);
    $refill_threshold = intval($_POST['refill_threshold']);
    $instructions = sanitize_textarea_field($_POST['instructions']);
    $schedule_type = sanitize_text_field($_POST['schedule_type']);
    $raw_assigned = sanitize_text_field($_POST['assigned_to']);
    $assigned_user_id = 0;
    $family_member_id = 0;
    $assigned_to_name = 'Unknown';
    $current_user_id = get_current_user_id();

    if (strpos($raw_assigned, 'user_') === 0) {
        $assigned_user_id = intval(str_replace('user_', '', $raw_assigned));
        $u = get_userdata($assigned_user_id);
        $assigned_to_name = $u ? $u->display_name : 'Self';
    } else {
        $family_member_id = intval($raw_assigned);
        $family_member_post = get_post($family_member_id);
        if ($family_member_post) {
            $assigned_to_name = $family_member_post->post_title;
        }
    }

    // Handle Dose Times
    $dose_times = array();
    if (isset($_POST['dose_time']) && is_array($_POST['dose_time'])) {
        foreach ($_POST['dose_time'] as $key => $time) {
            $dosage = isset($_POST['dose_amount'][$key]) ? sanitize_text_field($_POST['dose_amount'][$key]) : '';
            if ($time) {
                $dose_times[] = array(
                    'time' => sanitize_text_field($time),
                    'dosage' => $dosage
                );
            }
        }
    }

    // Update basic fields
    $post_data = array(
        'ID' => $med_id,
        'post_title' => $title,
    );
    wp_update_post($post_data);

    // Update Meta
    update_post_meta($med_id, 'stock_quantity', $stock_quantity);
    // SYNC REFILL LOGIC: Update base qty and date when stock is manually set
    update_post_meta($med_id, '_refill_base_qty', $stock_quantity);
    update_post_meta($med_id, '_refill_date', date('Y-m-d'));
    update_post_meta($med_id, 'refill_threshold', $refill_threshold);
    update_post_meta($med_id, 'instructions', $instructions);
    update_post_meta($med_id, 'schedule_type', $schedule_type);
    update_post_meta($med_id, 'family_member_id', $family_member_id);
    update_post_meta($med_id, 'assigned_user_id', $assigned_user_id);
    update_post_meta($med_id, 'refills_left', isset($_POST['refills_left']) ? intval($_POST['refills_left']) : 0);
    delete_post_meta($med_id, 'dose_times');
    update_post_meta($med_id, 'dose_times', $dose_times);

    // Weekly Logic
    $frequency_text = ucfirst($schedule_type);
    if ($schedule_type === 'weekly' || $schedule_type === 'as_needed') {
        $selected_weekdays = array();
        if (isset($_POST['weekdays']) && is_array($_POST['weekdays'])) {
            $selected_weekdays = array_map('sanitize_text_field', $_POST['weekdays']);
            $frequency_text = ($schedule_type === 'weekly' ? 'Weekly' : 'As Needed') . ' on ' . implode(', ', array_map('ucfirst', $selected_weekdays));
        }
        update_post_meta($med_id, 'selected_weekdays', $selected_weekdays);
        update_post_meta($med_id, 'start_date', sanitize_text_field($_POST['start_date']));
    } else {
        // Clear if not weekly
        delete_post_meta($med_id, 'selected_weekdays');
        delete_post_meta($med_id, 'start_date');
    }

    // Update legacy/helper metas
    update_post_meta($med_id, 'assigned_to', $assigned_to_name);

    update_post_meta($med_id, 'frequency', $frequency_text);
    update_post_meta($med_id, 'dosage', !empty($dose_times) ? $dose_times[0]['dosage'] . ' pill' : '');

    // --- CALCULATE NEXT DOSE TIME ---
    // Recalculate next_dose_time after edits
    if (function_exists('pillpalnow_calculate_next_dose_time')) {
        $next_dose_time = pillpalnow_calculate_next_dose_time($med_id);
        if ($next_dose_time) {
            update_post_meta($med_id, 'next_dose_time', $next_dose_time);
        } else {
            delete_post_meta($med_id, 'next_dose_time');
        }
    }

    // --- NOTIFICATION HANDLING ---
    // Check if assigned user changed
    $old_assigned_user_id = get_post_meta($med_id, 'assigned_user_id', true);
    $assignment_changed = ($old_assigned_user_id != $assigned_user_id);

    // Notify assigned member if assignment changed
    if ($assignment_changed && $assigned_user_id && $assigned_user_id != get_current_user_id()) {
        if (class_exists('PillPalNow_Notifications')) {
            PillPalNow_Notifications::create(
                $assigned_user_id,
                PillPalNow_Notifications::TYPE_ASSIGNED,
                "Medication Updated",
                wp_get_current_user()->display_name . " updated {$title} assigned to you",
                $med_id,
                home_url('/dashboard')
            );
        }
    }

    // Reschedule reminder notification for assigned user
    if ($assigned_user_id && function_exists('pillpalnow_calculate_next_dose_time')) {
        $next_dose_time = get_post_meta($med_id, 'next_dose_time', true);
        if ($next_dose_time && class_exists('PillPalNow_Notifications')) {
            // Clear old reminder notifications
            $old_reminders = get_posts(array(
                'post_type' => 'notification',
                'author' => $assigned_user_id,
                'posts_per_page' => -1,
                'meta_query' => array(
                    'relation' => 'AND',
                    array('key' => 'medication_id', 'value' => $med_id),
                    array('key' => 'type', 'value' => PillPalNow_Notifications::TYPE_REMINDER),
                    array('key' => 'status', 'value' => PillPalNow_Notifications::STATUS_UNREAD)
                )
            ));
            foreach ($old_reminders as $reminder) {
                wp_delete_post($reminder->ID, true);
            }

            // Create new reminder
            $time_display = date('g:i A, M j', $next_dose_time);
            PillPalNow_Notifications::create(
                $assigned_user_id,
                PillPalNow_Notifications::TYPE_REMINDER,
                "Medication Updated: {$title}",
                "Next dose at {$time_display}",
                $med_id,
                home_url('/dashboard')
            );
        }
    }

    // Redirect back
    if (isset($_REQUEST['redirect_to'])) {
        wp_redirect(esc_url_raw($_REQUEST['redirect_to']));
    } elseif ($assigned_to_family_id) {
        wp_redirect(get_permalink($assigned_to_family_id));
    } else {
        wp_redirect(home_url('/dashboard'));
    }
    exit;
}

// Retrieve Metadata for Display
$stock_quantity = get_post_meta($med_id, 'stock_quantity', true);
$refill_threshold = get_post_meta($med_id, 'refill_threshold', true);
$refills_left = get_post_meta($med_id, 'refills_left', true);
$instructions = get_post_meta($med_id, 'instructions', true);
$schedule_type = get_post_meta($med_id, 'schedule_type', true);
$family_member_id = get_post_meta($med_id, 'family_member_id', true);
$dose_times = get_post_meta($med_id, 'dose_times', true);
if (!is_array($dose_times))
    $dose_times = [];

get_header();
?>

<div class="app-container flex-col justify-between" style="min-height: 100vh;">
    <div class="container flex-1">

        <header class="app-header border-b border-gray-800">
            <a href="javascript:history.back()" class="flex items-center text-secondary">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5"></path>
                    <path d="M12 19l-7-7 7-7"></path>
                </svg>
            </a>
            <h1 class="text-lg font-bold">Edit Medication</h1>
            <div style="width: 24px;"></div>
        </header>

        <form method="post">
            <input type="hidden" name="action" value="pillpalnow_edit_medication">
            <?php wp_nonce_field('pillpalnow_edit_action', 'pillpalnow_edit_nonce'); ?>
            <?php if (isset($_REQUEST['redirect_to'])): ?>
                <input type="hidden" name="redirect_to"
                    value="<?php echo esc_url(sanitize_text_field($_REQUEST['redirect_to'])); ?>">
            <?php endif; ?>

            <main class="p-4 flex flex-col gap-6 pb-24">

                <!-- Drug Name -->
                <div class="form-group">
                    <label class="block text-sm font-semibold text-secondary mb-2">Drug Name</label>
                    <input type="text" name="post_title" class="input-field w-full"
                        value="<?php echo esc_attr($med->post_title); ?>" required>
                </div>

                <!-- Schedule -->
                <div class="form-group">
                    <label class="block text-sm font-semibold text-secondary mb-2">Schedule</label>
                    <div class="flex gap-2">
                        <label class="flex-1">
                            <input type="radio" name="schedule_type" value="daily" <?php checked($schedule_type, 'daily'); ?> class="hidden peer">
                            <div
                                class="btn btn-primary text-sm w-full text-center peer-checked:bg-primary peer-checked:text-white bg-card text-secondary cursor-pointer">
                                Daily</div>
                        </label>
                        <label class="flex-1">
                            <input type="radio" name="schedule_type" value="weekly" <?php checked($schedule_type, 'weekly'); ?> class="hidden peer">
                            <div
                                class="btn btn-primary text-sm w-full text-center peer-checked:bg-primary peer-checked:text-white bg-card text-secondary cursor-pointer">
                                Weekly</div>
                        </label>
                        <label class="flex-1">
                            <input type="radio" name="schedule_type" value="as_needed" <?php checked($schedule_type, 'as_needed'); ?> class="hidden peer">
                            <div
                                class="btn btn-primary text-sm w-full text-center peer-checked:bg-primary peer-checked:text-white bg-card text-secondary cursor-pointer">
                                As Needed</div>
                        </label>
                    </div>

                    <?php
                    $stored_weekdays = get_post_meta($med_id, 'selected_weekdays', true);
                    if (!is_array($stored_weekdays)) $stored_weekdays = [];
                    $stored_start_date = get_post_meta($med_id, 'start_date', true);
                    $is_weekly = ($schedule_type === 'weekly' || $schedule_type === 'as_needed');
                    ?>
                    <!-- Weekly Specific Options -->
                    <div id="weekly-options" class="mt-4 <?php echo $is_weekly ? '' : 'hidden'; ?> p-4 bg-gray-900 rounded-lg border border-gray-800">
                        <label class="block text-sm font-semibold text-white mb-3">
                            Select Days
                        </label>
                        <div class="grid grid-cols-7 gap-2 mb-4">
                            <?php
                            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                            foreach ($days as $day):
                                $val = strtolower($day);
                                $checked = in_array($val, $stored_weekdays) ? 'checked' : '';
                            ?>
                                <div class="relative">
                                    <input type="checkbox" name="weekdays[]" value="<?php echo $val; ?>" <?php echo $checked; ?>
                                        id="day-<?php echo $val; ?>" class="peer sr-only">
                                    <label for="day-<?php echo $val; ?>"
                                        class="block w-full text-center py-2 rounded bg-gray-800 text-secondary border border-gray-700 cursor-pointer transition-all peer-checked:bg-primary peer-checked:text-white peer-checked:border-primary peer-hover:border-gray-600 text-xs font-bold">
                                        <?php echo $day; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <label class="block text-sm font-semibold text-white mb-2">
                            Start Date
                        </label>
                        <input type="date" name="start_date" class="input-field w-full"
                            value="<?php echo esc_attr($stored_start_date); ?>">
                    </div>
                </div>

                <script>
                    const radios = document.getElementsByName('schedule_type');
                    const weeklyBox = document.getElementById('weekly-options');
                    radios.forEach(radio => {
                        radio.addEventListener('change', (e) => {
                            if (e.target.value === 'weekly' || e.target.value === 'as_needed') {
                                weeklyBox.classList.remove('hidden');
                            } else {
                                weeklyBox.classList.add('hidden');
                            }
                        });
                    });
                </script>

                <!-- Dose Times -->
                <div class="form-group">
                    <label class="block text-sm font-semibold text-secondary mb-2">Dose Times</label>
                    <div class="flex flex-col gap-3 time-container">
                        <?php if (!empty($dose_times)): ?>
                            <?php foreach ($dose_times as $dt): ?>
                                <div class="card p-3 flex justify-between items-center time-item">
                                    <div class="flex items-center gap-3">
                                        <div class="bg-gray-800 p-2 rounded text-primary">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                        </div>
                                        <input type="time" name="dose_time[]"
                                            class="input-field py-1 px-2 text-center w-32 bg-transparent border-none text-white font-bold text-lg"
                                            value="<?php echo esc_attr($dt['time']); ?>">
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="number" step="0.1" name="dose_amount[]"
                                            class="input-field py-1 px-2 text-center w-16"
                                            value="<?php echo esc_attr($dt['dosage']); ?>">
                                        <span class="text-sm text-secondary">pill</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Default empty slot if none exist -->
                            <div class="card p-3 flex justify-between items-center time-item">
                                <div class="flex items-center gap-3">
                                    <div class="bg-gray-800 p-2 rounded text-primary">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                    </div>
                                    <input type="time" name="dose_time[]"
                                        class="input-field py-1 px-2 text-center w-32 bg-transparent border-none text-white font-bold text-lg"
                                        value="08:00">
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="number" step="0.1" name="dose_amount[]"
                                        class="input-field py-1 px-2 text-center w-16" value="1.0">
                                    <span class="text-sm text-secondary">pill</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="button"
                        class="btn btn-secondary border-dashed text-primary text-sm w-full add-time-btn">
                        + Add Another Time
                    </button>
                </div>

                <!-- Inventory -->
                <div class="grid grid-cols-2 gap-4" style="display: grid; grid-template-columns: 1fr 1fr 1fr;">
                    <div>
                        <label class="block text-sm font-semibold text-secondary mb-2">Quantity Left</label>
                        <input type="number" name="stock_quantity" class="input-field w-full"
                            value="<?php echo esc_attr($stock_quantity); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-secondary mb-2">Refills Left</label>
                        <input type="number" name="refills_left" class="input-field w-full"
                            value="<?php echo esc_attr($refills_left); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-secondary mb-2">Refill Alert At</label>
                        <input type="number" name="refill_threshold" class="input-field w-full"
                            value="<?php echo esc_attr($refill_threshold); ?>">
                    </div>
                </div>

                <!-- Assign To -->
                <div class="form-group">
                    <label class="block text-sm font-semibold text-secondary mb-2">Assign To</label>
                    <select name="assigned_to" class="input-field w-full" style="appearance: none;">
                        <?php
                        $assigned_user_id_val = get_post_meta($med->ID, 'assigned_user_id', true);
                        $current_user = wp_get_current_user();
                        $is_self = (!empty($assigned_user_id_val) && $assigned_user_id_val == $current_user->ID);

                        // Option: Self
                        echo '<option value="user_' . esc_attr($current_user->ID) . '" ' . selected($is_self, true, false) . '>' . esc_html($current_user->display_name) . ' (Assigned)</option>';

                        $family_args = array(
                            'post_type' => 'family_member',
                            'posts_per_page' => -1,
                            'author' => get_current_user_id()
                        );
                        $family_members = get_posts($family_args);

                        if (!empty($family_members)):
                            foreach ($family_members as $fm): ?>
                                <option value="<?php echo esc_attr($fm->ID); ?>" <?php selected($family_member_id, $fm->ID); ?>>
                                    <?php echo esc_html($fm->post_title); ?>
                                </option>
                            <?php endforeach;
                        endif; ?>
                    </select>
                </div>

                <!-- Instructions -->
                <div class="form-group">
                    <label class="block text-sm font-semibold text-secondary mb-2">Instructions</label>
                    <textarea name="instructions"
                        class="input-field w-full h-24"><?php echo esc_textarea($instructions); ?></textarea>
                </div>

            </main>

            <div class="fixed bottom-0 left-0 right-0 p-4 bg-gray-900 border-t border-gray-800 mx-auto z-50 backdrop-blur"
                style="background: rgba(15, 23, 42, 0.95);">
                <div class="container flex gap-3">
                    <?php if (class_exists('PillPalNow_Permissions') && PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_DELETE_MEDICATION, true)): ?>
                    <a href="<?php echo esc_url(admin_url('admin-post.php?action=pillpalnow_delete_medication&med_id=' . $med_id . '&_wpnonce=' . wp_create_nonce('pillpalnow_delete_med_' . $med_id))); ?>"
                        class="btn btn-text text-danger w-1/3 text-center border border-red-900/50"
                        onclick="return confirm('Are you sure you want to delete this medication? This action cannot be undone.');">
                        Delete
                    </a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-2/3">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php get_footer(); ?>