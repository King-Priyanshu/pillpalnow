<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Data_Validator
 * Validates and heals data inconsistencies in PillPalNow CPTs
 */
class PillPalNow_Data_Validator
{
    private $issues = array();

    public function __construct()
    {
        // Run validation on admin init (throttled to once per day)
        add_action('admin_init', array($this, 'maybe_run_validation'));

        // Display admin notices
        add_action('admin_notices', array($this, 'display_validation_notices'));

        // Handle heal action
        add_action('admin_post_pillpalnow_heal_data', array($this, 'handle_heal_data'));
    }

    /**
     * Maybe run validation (throttled to once per day)
     */
    public function maybe_run_validation()
    {
        // Only run on PillPalNow admin pages
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array('medication', 'dose_log', 'reminder_log', 'refill_request'))) {
            return;
        }

        $last_check = get_option('pillpalnow_last_validation_check', 0);
        $current_time = time();

        // Check once per day
        if (($current_time - $last_check) < DAY_IN_SECONDS) {
            // Load cached issues
            $this->issues = get_option('pillpalnow_validation_issues', array());
            return;
        }

        // Run validation
        $this->run_validation();

        // Update last check time and cache issues
        update_option('pillpalnow_last_validation_check', $current_time);
        update_option('pillpalnow_validation_issues', $this->issues);
    }

    /**
     * Run validation checks
     */
    private function run_validation()
    {
        $this->issues = array();

        // Check dose logs
        $this->validate_dose_logs();

        // Check reminder logs
        $this->validate_reminder_logs();

        // Check refill requests
        $this->validate_refill_requests();

        // Check medications
        $this->validate_medications();
    }

    /**
     * Validate dose logs
     */
    private function validate_dose_logs()
    {
        $dose_logs = get_posts(array(
            'post_type' => 'dose_log',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        foreach ($dose_logs as $log) {
            // Check user_id
            $user_id = get_post_meta($log->ID, 'user_id', true);
            if (!$user_id) {
                $this->issues[] = array(
                    'type' => 'dose_log_missing_user',
                    'post_id' => $log->ID,
                    'message' => sprintf('Dose Log #%d is missing user_id', $log->ID)
                );
            } elseif (!get_userdata($user_id)) {
                $this->issues[] = array(
                    'type' => 'dose_log_invalid_user',
                    'post_id' => $log->ID,
                    'message' => sprintf('Dose Log #%d has invalid user_id: %d', $log->ID, $user_id)
                );
            }

            // Check medication_id
            $med_id = get_post_meta($log->ID, 'medication_id', true);
            if (!$med_id) {
                $this->issues[] = array(
                    'type' => 'dose_log_missing_medication',
                    'post_id' => $log->ID,
                    'message' => sprintf('Dose Log #%d is missing medication_id', $log->ID)
                );
            } elseif (!get_post($med_id)) {
                $this->issues[] = array(
                    'type' => 'dose_log_invalid_medication',
                    'post_id' => $log->ID,
                    'message' => sprintf('Dose Log #%d has invalid medication_id: %d', $log->ID, $med_id)
                );
            }

            // Check status
            $status = get_post_meta($log->ID, 'status', true);
            if (!$status) {
                $this->issues[] = array(
                    'type' => 'dose_log_missing_status',
                    'post_id' => $log->ID,
                    'message' => sprintf('Dose Log #%d is missing status', $log->ID)
                );
            }
        }
    }

    /**
     * Validate reminder logs
     */
    private function validate_reminder_logs()
    {
        $reminder_logs = get_posts(array(
            'post_type' => 'reminder_log',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        foreach ($reminder_logs as $log) {
            // Check user_id
            $user_id = get_post_meta($log->ID, 'user_id', true);
            if (!$user_id) {
                $this->issues[] = array(
                    'type' => 'reminder_log_missing_user',
                    'post_id' => $log->ID,
                    'message' => sprintf('Reminder Log #%d is missing user_id', $log->ID)
                );
            } elseif (!get_userdata($user_id)) {
                $this->issues[] = array(
                    'type' => 'reminder_log_invalid_user',
                    'post_id' => $log->ID,
                    'message' => sprintf('Reminder Log #%d has invalid user_id: %d', $log->ID, $user_id)
                );
            }

            // Check medication_id
            $med_id = get_post_meta($log->ID, 'medication_id', true);
            if (!$med_id) {
                $this->issues[] = array(
                    'type' => 'reminder_log_missing_medication',
                    'post_id' => $log->ID,
                    'message' => sprintf('Reminder Log #%d is missing medication_id', $log->ID)
                );
            } elseif (!get_post($med_id)) {
                $this->issues[] = array(
                    'type' => 'reminder_log_invalid_medication',
                    'post_id' => $log->ID,
                    'message' => sprintf('Reminder Log #%d has invalid medication_id: %d', $log->ID, $med_id)
                );
            }

            // Check status
            $status = get_post_meta($log->ID, 'status', true);
            if (!$status) {
                $this->issues[] = array(
                    'type' => 'reminder_log_missing_status',
                    'post_id' => $log->ID,
                    'message' => sprintf('Reminder Log #%d is missing status', $log->ID)
                );
            }
        }
    }

    /**
     * Validate refill requests
     */
    private function validate_refill_requests()
    {
        $refill_requests = get_posts(array(
            'post_type' => 'refill_request',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        foreach ($refill_requests as $request) {
            // Check medication_id
            $med_id = get_post_meta($request->ID, 'medication_id', true);
            if (!$med_id) {
                $this->issues[] = array(
                    'type' => 'refill_request_missing_medication',
                    'post_id' => $request->ID,
                    'message' => sprintf('Refill Request #%d is missing medication_id', $request->ID)
                );
            } elseif (!get_post($med_id)) {
                $this->issues[] = array(
                    'type' => 'refill_request_invalid_medication',
                    'post_id' => $request->ID,
                    'message' => sprintf('Refill Request #%d has invalid medication_id: %d', $request->ID, $med_id)
                );
            }
        }
    }

    /**
     * Validate medications
     */
    private function validate_medications()
    {
        $medications = get_posts(array(
            'post_type' => 'medication',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        foreach ($medications as $med) {
            // Check assigned_user_id if it exists
            $assigned_user_id = get_post_meta($med->ID, 'assigned_user_id', true);
            if ($assigned_user_id && !get_userdata($assigned_user_id)) {
                $this->issues[] = array(
                    'type' => 'medication_invalid_assigned_user',
                    'post_id' => $med->ID,
                    'message' => sprintf('Medication #%d has invalid assigned_user_id: %d', $med->ID, $assigned_user_id)
                );
            }
        }
    }

    /**
     * Display validation notices
     */
    public function display_validation_notices()
    {
        if (empty($this->issues)) {
            return;
        }

        $issue_count = count($this->issues);
        $heal_url = wp_nonce_url(admin_url('admin-post.php?action=pillpalnow_heal_data'), 'pillpalnow_heal_data');

        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>
                    <?php _e('PillPalNow Data Issues Detected:', 'pillpalnow'); ?>
                </strong>
                <?php printf(_n('%d data inconsistency found.', '%d data inconsistencies found.', $issue_count, 'pillpalnow'), $issue_count); ?>
            </p>
            <p>
                <a href="#" onclick="jQuery('#pillpalnow-issues-list').toggle(); return false;" class="button button-secondary">
                    <?php _e('View Issues', 'pillpalnow'); ?>
                </a>
                <a href="<?php echo esc_url($heal_url); ?>" class="button button-primary">
                    <?php _e('Heal Data', 'pillpalnow'); ?>
                </a>
            </p>
            <div id="pillpalnow-issues-list" style="display:none; margin-top: 10px;">
                <ul style="list-style: disc; padding-left: 20px;">
                    <?php foreach ($this->issues as $issue): ?>
                        <li>
                            <?php echo esc_html($issue['message']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Handle heal data action
     */
    public function handle_heal_data()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'pillpalnow'));
        }

        check_admin_referer('pillpalnow_heal_data');

        $healed_count = 0;

        // Heal dose logs
        $healed_count += $this->heal_dose_logs();

        // Heal reminder logs
        $healed_count += $this->heal_reminder_logs();

        // Clear validation cache
        delete_option('pillpalnow_validation_issues');
        delete_option('pillpalnow_last_validation_check');

        // Redirect with success message
        $redirect = add_query_arg(array(
            'pillpalnow_healed' => $healed_count
        ), wp_get_referer());

        wp_redirect($redirect);
        exit;
    }

    /**
     * Heal dose logs
     */
    private function heal_dose_logs()
    {
        $healed = 0;

        $dose_logs = get_posts(array(
            'post_type' => 'dose_log',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        foreach ($dose_logs as $log) {
            // Fix missing user_id (use post_author as fallback)
            $user_id = get_post_meta($log->ID, 'user_id', true);
            if (!$user_id && $log->post_author) {
                update_post_meta($log->ID, 'user_id', $log->post_author);
                $healed++;
            }

            // Fix missing status (default to 'taken')
            $status = get_post_meta($log->ID, 'status', true);
            if (!$status) {
                update_post_meta($log->ID, 'status', 'taken');
                $healed++;
            }
        }

        return $healed;
    }

    /**
     * Heal reminder logs
     */
    private function heal_reminder_logs()
    {
        $healed = 0;

        $reminder_logs = get_posts(array(
            'post_type' => 'reminder_log',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        foreach ($reminder_logs as $log) {
            // Fix missing user_id (use post_author as fallback)
            $user_id = get_post_meta($log->ID, 'user_id', true);
            if (!$user_id && $log->post_author) {
                update_post_meta($log->ID, 'user_id', $log->post_author);
                $healed++;
            }

            // Fix missing status (default to 'pending')
            $status = get_post_meta($log->ID, 'status', true);
            if (!$status) {
                update_post_meta($log->ID, 'status', 'pending');
                $healed++;
            }
        }

        return $healed;
    }

    /**
     * Centralized Daily Stats Calculation
     * 
     * @param int    $user_id
     * @param string $date (Y-m-d)
     * @return array [total_scheduled => int, taken_count => int, percentage => int]
     */
    public static function get_daily_stats($user_id, $date, $family_member_id = null)
    {
        $meta_query = array('relation' => 'AND');

        // Logic branching:
        // 1. If looking for specific family member (>0), search ONLY by family_member_id. 
        //    (Family meds have assigned_user_id=0, so the check would fail)
        // 2. If looking for Self (0 or null), search by assigned_user_id = $user_id.

        if ($family_member_id && $family_member_id > 0) {
            $meta_query[] = array('key' => 'family_member_id', 'value' => $family_member_id);
        } else {
            // Self / Default
            $meta_query[] = array('key' => 'assigned_user_id', 'value' => $user_id);

            // Explicitly exclude family assigned meds if we are looking for "Self"
            // (Though assigned_user_id check usually handles this, double safety)
            $meta_query[] = array(
                'relation' => 'OR',
                array('key' => 'family_member_id', 'value' => 0),
                array('key' => 'family_member_id', 'compare' => 'NOT EXISTS')
            );
        }

        $meds = get_posts(array(
            'post_type' => 'medication',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'author' => $user_id, // Only medications authored by current user
            'meta_query' => $meta_query
        ));

        $total_scheduled = 0;
        $taken_count = 0;
        $items = array();

        // ✅ PERFORMANCE: Bulk query optimization to prevent N+1 problem
        // Instead of querying dose logs for each medication, fetch all at once
        $med_ids = wp_list_pluck($meds, 'ID');

        if (empty($med_ids)) {
            return array(
                'scheduled' => 0,
                'taken' => 0,
                'percentage' => 0,
                'items' => array()
            );
        }

        // Fetch all dose logs for all medications in one query
        global $wpdb;
        $med_ids_placeholder = implode(',', array_map('intval', $med_ids));

        $dose_logs_query = $wpdb->prepare(
            "SELECT p.ID, pm1.meta_value as medication_id, pm2.meta_value as log_date, 
                    pm3.meta_value as status, pm4.meta_value as dose_index, pm5.meta_value as log_time
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'medication_id'
             INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'log_date'
             LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = 'status'
             LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = 'dose_index'
             LEFT JOIN {$wpdb->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = 'log_time'
             WHERE p.post_type = 'dose_log'
             AND pm1.meta_value IN ({$med_ids_placeholder})
             AND pm2.meta_value = %s",
            $date
        );

        $dose_logs_results = $wpdb->get_results($dose_logs_query);

        // Group logs by medication ID for easy lookup
        $logs_by_med = array();
        foreach ($dose_logs_results as $log_row) {
            $med_id = $log_row->medication_id;
            if (!isset($logs_by_med[$med_id])) {
                $logs_by_med[$med_id] = array();
            }
            $logs_by_med[$med_id][] = $log_row;
        }

        // Now process each medication with pre-fetched logs
        foreach ($meds as $med) {
            $schedule_type = get_post_meta($med->ID, 'schedule_type', true);
            $assignment_status = get_post_meta($med->ID, 'assignment_status', true) ?: 'accepted';
            if ($assignment_status === 'rejected')
                continue;

            $dose_times = get_post_meta($med->ID, 'dose_times', true) ?: array();

            // Filter by schedule type and date logic
            $is_scheduled_today = false;
            if ($schedule_type === 'daily') {
                $is_scheduled_today = true;
            } elseif ($schedule_type === 'weekly') {
                $day_slug = strtolower(date('D', strtotime($date)));
                $selected_weekdays = get_post_meta($med->ID, 'selected_weekdays', true) ?: array();
                if (in_array($day_slug, $selected_weekdays)) {
                    $start_date = get_post_meta($med->ID, 'start_date', true);
                    if (!$start_date || $date >= $start_date) {
                        $is_scheduled_today = true;
                    }
                }
            } elseif ($schedule_type === 'as_needed') {
                $is_scheduled_today = true;
            }

            if (!$is_scheduled_today)
                continue;

            // Get pre-fetched logs for this medication
            $logs = isset($logs_by_med[$med->ID]) ? $logs_by_med[$med->ID] : array();

            $non_indexed_taken = 0;

            $med_taken_indices = array();
            $med_skipped_indices = array();
            $as_needed_taken = 0;

            // NEW LOGIC: retroactive check
            $med_created_ts = strtotime($med->post_date);

            foreach ($logs as $log_row) {
                $s = $log_row->status ?: '';
                if ($s === 'superseded')
                    continue;

                // Sync Logic: Check log time vs med creation time
                if ($log_row->log_time) {
                    $log_ts = strtotime($date . ' ' . $log_row->log_time);
                    if ($log_ts < $med_created_ts) {
                        continue; // Retroactive log, ignore
                    }
                }

                $idx = $log_row->dose_index;

                if ($schedule_type === 'as_needed') {
                    if ($s === 'taken')
                        $as_needed_taken++;
                    continue;
                }

                if ($idx !== '' && $idx !== false && $idx !== null) {
                    $idx = (int) $idx;
                    if ($s === 'taken')
                        $med_taken_indices[] = $idx;
                    elseif ($s === 'skipped')
                        $med_skipped_indices[] = $idx;
                } else {
                    // Log has no index (Generic "Take Now"), count it
                    if ($s === 'taken') {
                        $non_indexed_taken++;
                    }
                }
            }

            if ($schedule_type !== 'as_needed') {
                $sched_count = 0;
                if (is_array($dose_times)) {
                    foreach ($dose_times as $dt) {
                        $dt_ts = strtotime($date . ' ' . $dt['time']);
                        // Only count if time is AFTER creation time
                        if ($dt_ts >= $med_created_ts) {
                            $sched_count++;
                        }
                    }
                }

                $total_scheduled += $sched_count;

                // Capped taken count for this med
                $unique_taken = count(array_unique($med_taken_indices));
                $unique_skipped = count(array_unique($med_skipped_indices));

                // Combine unique indexed shots + generic non-indexed shots
                $total_taken_calc = $unique_taken + $non_indexed_taken;

                // A dose is either Taken or Skipped. 
                // We only count 'taken' towards the numerator for status percentage.
                $taken_count += min($total_taken_calc, $sched_count);
            } else {
                // As Needed: Does it add to denominator? 
                // If it's taken, we add it to both to maintain percentage logic or keep it as "bonus".
                // To prevent > 100%, if we take an As Needed med, we MUST increase the denominator.
                $taken_count += $as_needed_taken;
                $total_scheduled += $as_needed_taken;
            }
        }

        $percentage = ($total_scheduled > 0) ? round(($taken_count / $total_scheduled) * 100) : 0;

        return array(
            'total_scheduled' => $total_scheduled,
            'taken_count' => $taken_count,
            'percentage' => $percentage
        );
    }
}

// Initialize the class
new PillPalNow_Data_Validator();
