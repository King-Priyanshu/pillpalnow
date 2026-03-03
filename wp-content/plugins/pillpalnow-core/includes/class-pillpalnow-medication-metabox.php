<?php
/**
 * Medication Meta Boxes for Admin
 * 
 * Provides full parity with frontend Add Medication form.
 * 
 * @package PillPalNow
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Medication_Metabox
 * 
 * Adds all medication meta boxes to admin CPT screen
 */
class PillPalNow_Medication_Metabox
{

    /**
     * Initialize the class
     */
    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_medication', array($this, 'save_meta_box'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        global $post_type;

        if ($post_type !== 'medication') {
            return;
        }

        wp_add_inline_style('wp-admin', $this->get_admin_styles());

        // Enqueue jQuery UI Autocomplete for drug search
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style('wp-jquery-ui-dialog');

        // Localize REST URL for AJAX calls
        wp_localize_script('jquery', 'pillpalnow_admin_vars', array(
            'rest_url' => rest_url('pillpalnow/v1/'),
            'nonce' => wp_create_nonce('wp_rest')
        ));

        wp_add_inline_script('jquery', $this->get_admin_scripts(), 'after');
    }

    /**
     * Register meta boxes
     */
    public function add_meta_boxes()
    {
        // Assignment (high priority, side)
        add_meta_box(
            'pillpalnow_assignment',
            __('Assignment', 'pillpalnow'),
            array($this, 'render_assignment_metabox'),
            'medication',
            'side',
            'high'
        );

        // Inventory & Refills (consolidated, side)
        add_meta_box(
            'pillpalnow_inventory_refills',
            __('Inventory & Refills', 'pillpalnow'),
            array($this, 'render_inventory_refills_metabox'),
            'medication',
            'side',
            'default'
        );

        // Drug Information (consolidated, main content)
        add_meta_box(
            'pillpalnow_drug_information',
            __('Drug Information', 'pillpalnow'),
            array($this, 'render_drug_info_metabox'),
            'medication',
            'normal',
            'high'
        );

        // Schedule Settings (main content)
        add_meta_box(
            'pillpalnow_schedule_settings',
            __('Schedule Settings', 'pillpalnow'),
            array($this, 'render_schedule_metabox'),
            'medication',
            'normal',
            'high'
        );
    }

    /**
     * Render Assignment Metabox
     */
    public function render_assignment_metabox($post)
    {
        $assigned_user_id = get_post_meta($post->ID, 'assigned_user_id', true);
        $family_member_id = get_post_meta($post->ID, 'family_member_id', true);

        // Build current value
        $current_value = '';
        if ($assigned_user_id) {
            $current_value = 'user_' . $assigned_user_id;
        } elseif ($family_member_id) {
            $current_value = $family_member_id;
        }

        // If no value set, default to post author
        if (empty($current_value)) {
            $current_value = 'user_' . $post->post_author;
        }
        ?>
        <p>
            <label for="pillpalnow_assigned_to"><strong><?php _e('Assigned To (Patient)', 'pillpalnow'); ?></strong></label>
        </p>
        <select name="pillpalnow_assigned_to" id="pillpalnow_assigned_to" class="widefat">
            <?php
            // Get all users who can be assigned medications
            $users = get_users(array('orderby' => 'display_name'));

            foreach ($users as $user) {
                $selected = ($current_value === 'user_' . $user->ID) ? 'selected' : '';
                printf(
                    '<option value="user_%d" %s>%s (%s)</option>',
                    $user->ID,
                    $selected,
                    esc_html($user->display_name),
                    esc_html($user->user_email)
                );

                // Get family members for this user
                $family_members = get_posts(array(
                    'post_type' => 'family_member',
                    'posts_per_page' => -1,
                    'post_status' => 'publish',
                    'author' => $user->ID,
                    'meta_query' => array(
                        array(
                            'key' => 'status',
                            'value' => 'active',
                            'compare' => '='
                        )
                    )
                ));

                if (!empty($family_members)) {
                    foreach ($family_members as $fm) {
                        $relation_type = get_post_meta($fm->ID, 'relation_type', true) ?: get_post_meta($fm->ID, 'relation', true);
                        $relation_label = $relation_type ? ' - ' . ucfirst($relation_type) : '';
                        $selected = ($current_value == $fm->ID) ? 'selected' : '';

                        printf(
                            '<option value="%d" %s>  ↳ %s%s</option>',
                            $fm->ID,
                            $selected,
                            esc_html($fm->post_title),
                            esc_html($relation_label)
                        );
                    }
                }
            }
            ?>
        </select>
        <p class="description">
            <?php _e('Select the person who will be taking this medication. Family members are grouped under their account owner.', 'pillpalnow'); ?>
        </p>
        <?php
    }

    /**
     * Render Drug Information Metabox (Consolidated)
     * Combines drug search, RxCUI, and instructions
     */
    public function render_drug_info_metabox($post)
    {
        wp_nonce_field('pillpalnow_medication_metabox_nonce', 'pillpalnow_medication_nonce');

        $rxcui = get_post_meta($post->ID, 'rxcui', true);
        $instructions = get_post_meta($post->ID, 'instructions', true);
        ?>
        <div class="pillpalnow-drug-search-wrapper">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="pillpalnow_drug_search"><?php _e('Drug Name', 'pillpalnow'); ?></label>
                    </th>
                    <td>
                        <div class="pillpalnow-search-container">
                            <input type="text" id="pillpalnow_drug_search" class="large-text pillpalnow-drug-input"
                                value="<?php echo esc_attr($post->post_title); ?>"
                                placeholder="<?php esc_attr_e('Start typing to search RxNorm database...', 'pillpalnow'); ?>"
                                autocomplete="off" />
                            <span class="pillpalnow-search-spinner spinner" style="float: none; margin-top: 0;"></span>
                        </div>
                        <input type="hidden" id="pillpalnow_rxcui_hidden" name="pillpalnow_rxcui"
                            value="<?php echo esc_attr($rxcui); ?>" />
                        <div id="pillpalnow-drug-results" class="pillpalnow-autocomplete-results" style="display: none;"></div>
                        <p class="description">
                            <?php _e('Type at least 3 characters to search the RxNorm drug database. Select a drug to auto-fill the medication name and RxCUI.', 'pillpalnow'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php _e('RxCUI', 'pillpalnow'); ?></label>
                    </th>
                    <td>
                        <?php if ($rxcui): ?>
                            <code class="pillpalnow-rxcui-badge"><?php echo esc_html($rxcui); ?></code>
                            <p class="description">
                                <?php _e('RxNorm Concept Unique Identifier (auto-filled from drug search)', 'pillpalnow'); ?>
                            </p>
                        <?php else: ?>
                            <em
                                class="description"><?php _e('No RxCUI set. Use the drug search above to auto-fill.', 'pillpalnow'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pillpalnow_instructions"><?php _e('Instructions', 'pillpalnow'); ?></label>
                    </th>
                    <td>
                        <textarea name="pillpalnow_instructions" id="pillpalnow_instructions" class="large-text" rows="3"
                            placeholder="<?php esc_attr_e('E.g., Take with food, avoid alcohol, etc.', 'pillpalnow'); ?>"><?php echo esc_textarea($instructions); ?></textarea>
                        <p class="description">
                            <?php _e('Optional instructions or notes about how to take this medication.', 'pillpalnow'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <style>
            .pillpalnow-rxcui-badge {
                display: inline-block;
                padding: 4px 8px;
                background: #f0f0f1;
                border: 1px solid #dcdcde;
                border-radius: 3px;
                font-size: 13px;
                font-weight: 500;
                color: #2271b1;
            }
        </style>
        <?php
    }

    /**
     * Render Schedule Settings Metabox
     */
    public function render_schedule_metabox($post)
    {
        $schedule_type = get_post_meta($post->ID, 'schedule_type', true) ?: 'daily';
        $dose_times = get_post_meta($post->ID, 'dose_times', true) ?: array(array('time' => '08:00', 'dosage' => '1'));
        $selected_weekdays = get_post_meta($post->ID, 'selected_weekdays', true) ?: array();
        $start_date = get_post_meta($post->ID, 'start_date', true) ?: date('Y-m-d');

        if (!is_array($dose_times)) {
            $dose_times = array(array('time' => '08:00', 'dosage' => '1'));
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Schedule Type', 'pillpalnow'); ?></th>
                <td>
                    <div class="pillpalnow-schedule-buttons">
                        <label class="pillpalnow-schedule-btn <?php echo $schedule_type === 'daily' ? 'active' : ''; ?>">
                            <input type="radio" name="pillpalnow_schedule_type" value="daily" <?php checked($schedule_type, 'daily'); ?>>
                            <?php _e('Daily', 'pillpalnow'); ?>
                        </label>
                        <label class="pillpalnow-schedule-btn <?php echo $schedule_type === 'weekly' ? 'active' : ''; ?>">
                            <input type="radio" name="pillpalnow_schedule_type" value="weekly" <?php checked($schedule_type, 'weekly'); ?>>
                            <?php _e('Weekly', 'pillpalnow'); ?>
                        </label>
                        <label class="pillpalnow-schedule-btn <?php echo $schedule_type === 'as_needed' ? 'active' : ''; ?>">
                            <input type="radio" name="pillpalnow_schedule_type" value="as_needed" <?php checked($schedule_type, 'as_needed'); ?>>
                            <?php _e('As Needed', 'pillpalnow'); ?>
                        </label>
                    </div>
                </td>
            </tr>
            <tr class="pillpalnow-weekly-options"
                style="<?php echo in_array($schedule_type, array('weekly', 'as_needed')) ? '' : 'display:none;'; ?>">
                <th scope="row"><?php _e('Select Days', 'pillpalnow'); ?></th>
                <td>
                    <div class="pillpalnow-weekday-grid">
                        <?php
                        $days = array('mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun');
                        foreach ($days as $value => $label): ?>
                            <label
                                class="pillpalnow-weekday-btn <?php echo in_array($value, (array) $selected_weekdays) ? 'active' : ''; ?>">
                                <input type="checkbox" name="pillpalnow_weekdays[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, (array) $selected_weekdays)); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
            <tr class="pillpalnow-weekly-options"
                style="<?php echo in_array($schedule_type, array('weekly', 'as_needed')) ? '' : 'display:none;'; ?>">
                <th scope="row">
                    <label for="pillpalnow_start_date"><?php _e('Start Date', 'pillpalnow'); ?></label>
                </th>
                <td>
                    <input type="date" name="pillpalnow_start_date" id="pillpalnow_start_date"
                        value="<?php echo esc_attr($start_date); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Dose Times', 'pillpalnow'); ?></th>
                <td>
                    <div id="pillpalnow-dose-times-container">
                        <?php foreach ($dose_times as $index => $dose): ?>
                            <div class="pillpalnow-dose-time-row">
                                <input type="time" name="pillpalnow_dose_time[]"
                                    value="<?php echo esc_attr($dose['time'] ?? '08:00'); ?>" class="pillpalnow-time-input">
                                <input type="number" name="pillpalnow_dose_amount[]"
                                    value="<?php echo esc_attr($dose['dosage'] ?? '1'); ?>" step="0.5" min="0.5"
                                    class="pillpalnow-amount-input">
                                <span class="pillpalnow-pill-label"><?php _e('pill(s)', 'pillpalnow'); ?></span>
                                <button type="button" class="button pillpalnow-remove-time"
                                    title="<?php esc_attr_e('Remove', 'pillpalnow'); ?>">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="pillpalnow-add-dose-time">
                        + <?php _e('Add Another Time', 'pillpalnow'); ?>
                    </button>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Inventory & Refills Metabox (Consolidated)
     * Combines inventory tracking and refill alert settings
     */
    public function render_inventory_refills_metabox($post)
    {
        $stock_quantity = get_post_meta($post->ID, 'stock_quantity', true);
        $refills_left = get_post_meta($post->ID, 'refills_left', true);
        $refill_size = get_post_meta($post->ID, 'refill_size', true);
        $threshold = get_post_meta($post->ID, 'refill_threshold', true);
        $alerts_enabled = get_post_meta($post->ID, 'refill_alerts_enabled', true);

        // Defaults for new posts
        if ($stock_quantity === '')
            $stock_quantity = 30;
        if ($refills_left === '')
            $refills_left = 0;
        if ($refill_size === '')
            $refill_size = 30;
        if ($threshold === '')
            $threshold = 5;
        if ($alerts_enabled === '')
            $alerts_enabled = '1';
        ?>
        <div class="pillpalnow-inventory-refills">
            <p>
                <label for="pillpalnow_stock_quantity"><strong><?php _e('Current Stock', 'pillpalnow'); ?></strong></label>
                <input type="number" name="pillpalnow_stock_quantity" id="pillpalnow_stock_quantity" class="widefat"
                    value="<?php echo esc_attr($stock_quantity); ?>" min="0">
                <span class="description"><?php _e('Pills/doses on hand', 'pillpalnow'); ?></span>
            </p>

            <p>
                <label for="pillpalnow_refill_size"><strong><?php _e('Refill Pack Size', 'pillpalnow'); ?></strong></label>
                <input type="number" name="pillpalnow_refill_size" id="pillpalnow_refill_size" class="widefat"
                    value="<?php echo esc_attr($refill_size); ?>" min="1">
                <span class="description"><?php _e('Quantity when refilled', 'pillpalnow'); ?></span>
            </p>

            <p>
                <label for="pillpalnow_refills_left"><strong><?php _e('Refills Left', 'pillpalnow'); ?></strong></label>
                <input type="number" name="pillpalnow_refills_left" id="pillpalnow_refills_left" class="widefat"
                    value="<?php echo esc_attr($refills_left); ?>" min="0">
                <span class="description"><?php _e('Remaining refills', 'pillpalnow'); ?></span>
            </p>

            <hr style="margin: 15px 0; border: none; border-top: 1px solid #dcdcde;">

            <p>
                <label for="pillpalnow_refill_threshold"><strong><?php _e('Alert Threshold', 'pillpalnow'); ?></strong></label>
                <input type="number" id="pillpalnow_refill_threshold" name="pillpalnow_refill_threshold"
                    value="<?php echo esc_attr($threshold); ?>" min="0" class="widefat">
                <span class="description"><?php _e('Alert when stock reaches this level', 'pillpalnow'); ?></span>
            </p>

            <p>
                <label>
                    <input type="checkbox" id="pillpalnow_refill_alerts_enabled" name="pillpalnow_refill_alerts_enabled" value="1"
                        <?php checked($alerts_enabled, '1'); ?>>
                    <strong><?php _e('Enable Refill Alerts', 'pillpalnow'); ?></strong>
                </label>
                <br><span class="description"><?php _e('Send notifications when stock is low', 'pillpalnow'); ?></span>
            </p>

            <?php if ($stock_quantity !== '' && $threshold > 0 && $stock_quantity <= $threshold): ?>
                <div style="padding: 10px; background: #fcf0f1; border-left: 3px solid #d63638; margin-top: 10px;">
                    <p style="margin: 0; color: #d63638; font-weight: 500;">
                        ⚠️ <?php _e('Stock is currently below threshold!', 'pillpalnow'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save meta box data
     */
    public function save_meta_box($post_id, $post)
    {
        // Security checks
        if (!isset($_POST['pillpalnow_medication_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['pillpalnow_medication_nonce'], 'pillpalnow_medication_metabox_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save Instructions
        if (isset($_POST['pillpalnow_instructions'])) {
            update_post_meta($post_id, 'instructions', sanitize_textarea_field($_POST['pillpalnow_instructions']));
        }

        // Save RxCUI
        if (isset($_POST['pillpalnow_rxcui'])) {
            update_post_meta($post_id, 'rxcui', sanitize_text_field($_POST['pillpalnow_rxcui']));
        }

        // Save Schedule Type
        if (isset($_POST['pillpalnow_schedule_type'])) {
            $schedule_type = sanitize_text_field($_POST['pillpalnow_schedule_type']);
            update_post_meta($post_id, 'schedule_type', $schedule_type);

            // Build frequency text
            $frequency_text = ucfirst($schedule_type);
            $selected_weekdays = array();

            if (in_array($schedule_type, array('weekly', 'as_needed'))) {
                if (isset($_POST['pillpalnow_weekdays']) && is_array($_POST['pillpalnow_weekdays'])) {
                    $selected_weekdays = array_map('sanitize_text_field', $_POST['pillpalnow_weekdays']);
                    $prefix = ($schedule_type === 'weekly') ? 'Weekly' : 'As Needed';
                    $frequency_text = $prefix . ' on ' . implode(', ', array_map('ucfirst', $selected_weekdays));
                }
            }

            update_post_meta($post_id, 'selected_weekdays', $selected_weekdays);
            update_post_meta($post_id, 'frequency', $frequency_text);
        }

        // Save Start Date
        if (isset($_POST['pillpalnow_start_date'])) {
            update_post_meta($post_id, 'start_date', sanitize_text_field($_POST['pillpalnow_start_date']));
        }

        // Save Dose Times
        if (isset($_POST['pillpalnow_dose_time']) && is_array($_POST['pillpalnow_dose_time'])) {
            $dose_times = array();
            foreach ($_POST['pillpalnow_dose_time'] as $key => $time) {
                if (!empty($time)) {
                    $dosage = isset($_POST['pillpalnow_dose_amount'][$key]) ? sanitize_text_field($_POST['pillpalnow_dose_amount'][$key]) : '1';
                    $dose_times[] = array(
                        'time' => sanitize_text_field($time),
                        'dosage' => $dosage
                    );
                }
            }
            delete_post_meta($post_id, 'dose_times');
            update_post_meta($post_id, 'dose_times', $dose_times);

            // Also set legacy dosage field
            if (!empty($dose_times)) {
                update_post_meta($post_id, 'dosage', $dose_times[0]['dosage'] . ' pill');
            }
        }

        // Save Inventory
        if (isset($_POST['pillpalnow_stock_quantity'])) {
            $stock = absint($_POST['pillpalnow_stock_quantity']);
            $old_stock = get_post_meta($post_id, 'stock_quantity', true);

            update_post_meta($post_id, 'stock_quantity', $stock);

            // Set refill base (Always sync on manual update)
            update_post_meta($post_id, '_refill_base_qty', $stock);
            update_post_meta($post_id, '_refill_date', date('Y-m-d'));

            // Trigger stock update action for auto-refill system
            do_action('pillpalnow/stock_updated', $post_id, $stock);
        }

        if (isset($_POST['pillpalnow_refills_left'])) {
            update_post_meta($post_id, 'refills_left', absint($_POST['pillpalnow_refills_left']));
        }

        if (isset($_POST['pillpalnow_refill_size'])) {
            update_post_meta($post_id, 'refill_size', absint($_POST['pillpalnow_refill_size']));
        }

        // Save Refill Settings
        if (isset($_POST['pillpalnow_refill_threshold'])) {
            update_post_meta($post_id, 'refill_threshold', absint($_POST['pillpalnow_refill_threshold']));
        }
        $alerts_enabled = isset($_POST['pillpalnow_refill_alerts_enabled']) ? '1' : '0';
        update_post_meta($post_id, 'refill_alerts_enabled', $alerts_enabled);

        // Save Assignment
        if (isset($_POST['pillpalnow_assigned_to'])) {
            $raw_assigned = sanitize_text_field($_POST['pillpalnow_assigned_to']);
            $assigned_user_id = 0;
            $family_member_id = 0;
            $assigned_to_name = 'Unknown';

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

            $old_assigned_user_id = get_post_meta($post_id, 'assigned_user_id', true);
            $assignment_changed = ($old_assigned_user_id != $assigned_user_id);

            update_post_meta($post_id, 'assigned_to', $assigned_to_name);
            update_post_meta($post_id, 'assigned_user_id', $assigned_user_id);
            update_post_meta($post_id, 'family_member_id', $family_member_id);

            // Notify if assignment changed
            if ($assignment_changed && $assigned_user_id && $assigned_user_id != get_current_user_id()) {
                if (class_exists('PillPalNow_Notifications')) {
                    $med_title = get_the_title($post_id);
                    $current_user = wp_get_current_user();
                    $creator_name = $current_user ? $current_user->display_name : 'Admin';
                    PillPalNow_Notifications::create(
                        $assigned_user_id,
                        PillPalNow_Notifications::TYPE_ASSIGNED,
                        "Medication Updated",
                        "{$creator_name} updated {$med_title} assigned to you",
                        $post_id,
                        home_url('/dashboard')
                    );
                }
            }
        }

        // --- CALCULATE NEXT DOSE TIME ---
        // Recalculate after all meta updates
        if (class_exists('PillPalNow_Form_Handlers') && method_exists('PillPalNow_Form_Handlers', 'calculate_next_dose_time')) {
            $next_dose_time = PillPalNow_Form_Handlers::calculate_next_dose_time($post_id);
            if ($next_dose_time) {
                update_post_meta($post_id, 'next_dose_time', $next_dose_time);
            } else {
                delete_post_meta($post_id, 'next_dose_time');
            }

            // Reschedule reminder notifications
            $assigned_user_id = get_post_meta($post_id, 'assigned_user_id', true);
            if ($assigned_user_id && $next_dose_time && class_exists('PillPalNow_Notifications')) {
                // Clear old reminder notifications
                $old_reminders = get_posts(array(
                    'post_type' => 'notification',
                    'author' => $assigned_user_id,
                    'posts_per_page' => -1,
                    'meta_query' => array(
                        'relation' => 'AND',
                        array('key' => 'medication_id', 'value' => $post_id),
                        array('key' => 'type', 'value' => PillPalNow_Notifications::TYPE_REMINDER),
                        array('key' => 'status', 'value' => PillPalNow_Notifications::STATUS_UNREAD)
                    )
                ));
                foreach ($old_reminders as $reminder) {
                    wp_delete_post($reminder->ID, true);
                }

                // Create new reminder
                $med_title = get_the_title($post_id);
                $time_display = date('g:i A, M j', $next_dose_time);
                PillPalNow_Notifications::create(
                    $assigned_user_id,
                    PillPalNow_Notifications::TYPE_REMINDER,
                    "Medication Updated: {$med_title}",
                    "Next dose at {$time_display}",
                    $post_id,
                    home_url('/dashboard')
                );
            }
        }
    }

    /**
     * Get admin styles
     */
    private function get_admin_styles()
    {
        return '
        .pillpalnow-schedule-buttons {
            display: flex;
            gap: 10px;
        }
        .pillpalnow-schedule-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #f0f0f1;
            border: 2px solid #dcdcde;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .pillpalnow-schedule-btn:hover {
            border-color: #2271b1;
        }
        .pillpalnow-schedule-btn.active {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
        }
        .pillpalnow-schedule-btn input[type="radio"] {
            display: none;
        }
        .pillpalnow-weekday-grid {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .pillpalnow-weekday-btn {
            display: inline-block;
            padding: 6px 12px;
            background: #f0f0f1;
            border: 2px solid #dcdcde;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }
        .pillpalnow-weekday-btn:hover {
            border-color: #2271b1;
        }
        .pillpalnow-weekday-btn.active {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
        }
        .pillpalnow-weekday-btn input[type="checkbox"] {
            display: none;
        }
        .pillpalnow-dose-time-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #dcdcde;
            border-radius: 4px;
        }
        .pillpalnow-time-input {
            width: 120px;
        }
        .pillpalnow-amount-input {
            width: 70px;
        }
        .pillpalnow-pill-label {
            color: #666;
        }
        .pillpalnow-remove-time {
            color: #b32d2e;
            font-weight: bold;
            font-size: 16px;
            padding: 2px 8px;
        }
        #pillpalnow-add-dose-time {
            margin-top: 5px;
        }
        /* RxNorm Drug Search Styles */
        .pillpalnow-drug-search-wrapper {
            position: relative;
        }
        .pillpalnow-search-container {
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pillpalnow-drug-input {
            padding-right: 30px;
        }
        .pillpalnow-drug-input.loading {
            background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%232271b1\' stroke-width=\'2\'%3E%3Ccircle cx=\'12\' cy=\'12\' r=\'10\' stroke-opacity=\'.25\'/%3E%3Cpath d=\'M12 2a10 10 0 0 1 10 10\' stroke-linecap=\'round\'%3E%3CanimateTransform attributeName=\'transform\' type=\'rotate\' from=\'0 12 12\' to=\'360 12 12\' dur=\'1s\' repeatCount=\'indefinite\'/%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 20px;
        }
        .pillpalnow-drug-input.selected {
            border-color: #00a32a;
            background-color: #f0fff0;
        }
        .pillpalnow-autocomplete-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 100000;
            background: #fff;
            border: 1px solid #dcdcde;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 300px;
            overflow-y: auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .pillpalnow-drug-result {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f1;
            transition: background 0.15s;
        }
        .pillpalnow-drug-result:hover,
        .pillpalnow-drug-result.selected {
            background: #f0f7ff;
        }
        .pillpalnow-drug-result:last-child {
            border-bottom: none;
        }
        .pillpalnow-drug-result .drug-name {
            font-weight: 500;
            color: #1d2327;
        }
        .pillpalnow-drug-result .drug-rxcui {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        .pillpalnow-no-results {
            padding: 12px;
            color: #666;
            font-style: italic;
        }
        ';
    }

    /**
     * Get admin scripts
     */
    private function get_admin_scripts()
    {
        return '
jQuery(document).ready(function($) {
// Schedule type toggle
$(".pillpalnow-schedule-btn").on("click", function() {
$(".pillpalnow-schedule-btn").removeClass("active");
$(this).addClass("active");

var val = $(this).find("input[type=radio]").val();
if (val === "weekly" || val === "as_needed") {
$(".pillpalnow-weekly-options").show();
} else {
$(".pillpalnow-weekly-options").hide();
}
});

// Weekday toggle
$(".pillpalnow-weekday-btn").on("click", function() {
$(this).toggleClass("active");
});

// Add dose time
$("#pillpalnow-add-dose-time").on("click", function() {
var html = \'<div class="pillpalnow-dose-time-row">\' +
    \'<input type="time" name="pillpalnow_dose_time[]" value="08:00" class="pillpalnow-time-input">\' +
    \'<input type="number" name="pillpalnow_dose_amount[]" value="1" step="0.5" min="0.5" class="pillpalnow-amount-input">\'
    +
    \'<span class="pillpalnow-pill-label">pill(s)</span>\' +
    \'<button type="button" class="button pillpalnow-remove-time" title="Remove">&times;</button>\' +
    \'</div>\';
$("#pillpalnow-dose-times-container").append(html);
});

// Remove dose time
$(document).on("click", ".pillpalnow-remove-time", function() {
if ($(".pillpalnow-dose-time-row").length > 1) {
$(this).closest(".pillpalnow-dose-time-row").remove();
}
});

// RxNorm Drug Search Autocomplete
var $drugInput = $("#pillpalnow_drug_search");
var $rxcuiInput = $("#pillpalnow_rxcui_hidden");
var $resultsContainer = $("#pillpalnow-drug-results");
var searchTimer = null;
var selectedIndex = -1;

if ($drugInput.length && typeof pillpalnow_admin_vars !== "undefined") {
var restUrl = pillpalnow_admin_vars.rest_url;

$drugInput.on("input", function() {
var query = $(this).val().trim();

clearTimeout(searchTimer);
selectedIndex = -1;
$rxcuiInput.val("");
$drugInput.removeClass("selected");

if (query.length < 3) { $resultsContainer.hide().empty(); $drugInput.removeClass("loading"); return; }
    $drugInput.addClass("loading"); searchTimer=setTimeout(function() { $.ajax({ url: restUrl + "drug-search" ,
    method: "GET" , data: { keyword: query }, success: function(response) { $resultsContainer.empty();
    $drugInput.removeClass("loading"); if (response.success && response.data && response.data.length> 0) {
    response.data.forEach(function(item, index) {
    var $item = $(\'<div class="pillpalnow-drug-result" data-index="\' + index + \'">\' +
        \'<div class="drug-name">\' + escapeHtml(item.label) + \'</div>\' +
        \'<div class="drug-rxcui">RxCUI: \' + escapeHtml(item.value) + \'</div>\' +
        \'</div>\');

    $item.on("click", function() {
    selectDrug(item);
    });

    $resultsContainer.append($item);
    });
    $resultsContainer.show();
    } else {
    $resultsContainer.html(\'<div class="pillpalnow-no-results">No results found</div>\').show();
    }
    },
    error: function() {
    $drugInput.removeClass("loading");
    $resultsContainer.html(\'<div class="pillpalnow-no-results">Error searching drugs</div>\').show();
    }
    });
    }, 250);
    });

    function selectDrug(item) {
    $drugInput.val(item.label).addClass("selected");
    $rxcuiInput.val(item.value);
    // Also update the visible RxCUI field in Medication Details metabox
    $("#pillpalnow_rxcui").val(item.value);
    $resultsContainer.hide().empty();

    // Update WordPress post title
    if (typeof wp !== "undefined" && wp.data && wp.data.dispatch) {
    wp.data.dispatch("core/editor").editPost({ title: item.label });
    }
    if ($("#title").length) {
    $("#title").val(item.label);
    }
    }

    // Keyboard navigation
    $drugInput.on("keydown", function(e) {
    var $items = $resultsContainer.find(".pillpalnow-drug-result");
    if (!$items.length) return;

    if (e.key === "ArrowDown") {
    e.preventDefault();
    selectedIndex++;
    if (selectedIndex >= $items.length) selectedIndex = 0;
    highlightItem($items);
    } else if (e.key === "ArrowUp") {
    e.preventDefault();
    selectedIndex--;
    if (selectedIndex < 0) selectedIndex=$items.length - 1; highlightItem($items); } else if (e.key==="Enter" ) {
        e.preventDefault(); if (selectedIndex> -1 && $items[selectedIndex]) {
        $($items[selectedIndex]).click();
        }
        } else if (e.key === "Escape") {
        $resultsContainer.hide();
        }
        });

        function highlightItem($items) {
        $items.removeClass("selected");
        $($items[selectedIndex]).addClass("selected");
        }

        // Close dropdown on click outside
        $(document).on("click", function(e) {
        if (!$(e.target).closest(".pillpalnow-drug-search-wrapper").length) {
        $resultsContainer.hide();
        }
        });

        function escapeHtml(text) {
        if (!text) return text;
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
        }
        }
        });
        ';
    }
}

// Initialize the class
new PillPalNow_Medication_Metabox();
