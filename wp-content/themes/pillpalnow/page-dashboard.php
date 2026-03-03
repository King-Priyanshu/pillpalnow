<?php
/**
 * Template Name: Dashboard Page
 *
 * @package PillPalNow
 */


// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(home_url('/login'));
    exit;
}

// Check for Self View
if (isset($_GET['view']) && $_GET['view'] === 'self') {
    // We can include the template part for Self View
    // Make sure the file exists or is handled
    include get_template_directory() . '/view-self.php';
    exit;
}

$current_user = wp_get_current_user();
$current_user_id = $current_user->ID;
// Force UTC
date_default_timezone_set('UTC');
$current_time = current_time('timestamp', 1); // 1 = GMT/UTC
$today_date = date('Y-m-d', $current_time);
$today_day_slug = strtolower(date('D', $current_time));

// --- DATA GATHERING ---

// 1. Get Family Members
$is_family_member_role = in_array('family_member', (array) $current_user->roles) && !current_user_can('edit_users');

if ($is_family_member_role) {
    // FAMILY MEMBER VIEW: Only show self
    $family_members = array();
    $self_member = new stdClass();
    $self_member->ID = 0;
    $self_member->post_title = $current_user->display_name;
    $self_member->is_self_user = true;
    $family_members[] = $self_member;
} else {
    // PARENT VIEW: Show all
    $family_members = get_posts(array(
        'post_type' => 'family_member',
        'posts_per_page' => -1,
        'author' => $current_user_id
    ));

    // Add "Self" as a pseudo-member
    $self_member = new stdClass();
    $self_member->ID = 0;
    $self_member->post_title = 'Self';
    $self_member->is_self_user = true;
    array_unshift($family_members, $self_member);
}

$daily_doses = array();
$weekly_doses = array();
$as_needed_doses = array(); // New
$low_stock_meds = array();
$family_stats = array();
$next_dose_timestamp = null;
$active_meds_count = 0; // Track total active medications

foreach ($family_members as $member) {
    $member_id = $member->ID;
    $member_name = $member->post_title;

    // Check if this member is the current user

    if (isset($member->is_self_user) && $member->is_self_user) {
        $is_me = true;

        if ($is_family_member_role) {
            // --- STRICT FAMILY MEMBER QUERY ---
            // Only show meds explicitly assigned to this User ID
            $meds = get_posts(array(
                'post_type' => 'medication',
                'posts_per_page' => -1,
                // Note: Do NOT restrict by 'author' because Parent creates them
                'meta_query' => array(
                    array('key' => 'assigned_family_member_id', 'value' => $current_user_id)
                )
            ));
        } else {
            // --- PARENT / SELF QUERY ---
            // Query meds assigned specifically to user ID OR legacy "Self" (family_member_id = 0)
            $meds = get_posts(array(
                'post_type' => 'medication',
                'posts_per_page' => -1,
                'author' => $current_user_id,
                'meta_query' => array(
                    'relation' => 'OR',
                    array('key' => 'assigned_user_id', 'value' => $current_user_id),
                    array('key' => 'assigned_to', 'value' => 'Self'), // Legacy safe-guard
                    array(
                        'relation' => 'AND',
                        array('key' => 'family_member_id', 'compare' => 'NOT EXISTS'),
                        array('key' => 'assigned_user_id', 'compare' => 'NOT EXISTS')
                    )
                )
            ));
        }
    } else {
        $linked_user = get_post_meta($member_id, 'linked_user_id', true);
        $relation = get_post_meta($member_id, 'relation', true);
        $is_me = ($linked_user == $current_user_id) ||
            (in_array(strtolower($relation), ['self', 'me', 'myself']));

        // Get Meds by Family ID - STRICTLY enforce assigned_user_id
        $meds = get_posts(array(
            'post_type' => 'medication',
            'posts_per_page' => -1,
            'author' => $current_user_id,
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => 'family_member_id', 'value' => $member_id),
                array('key' => 'assigned_user_id', 'value' => $current_user_id) // STRICT ISOLATION
            )
        ));
    }

    $total_scheduled = 0;
    $taken_count = 0;

    foreach ($meds as $med) {
        // --- Refill Check ---
        if (function_exists('pillpalnow_get_remaining_stock')) {
            $stock = (int) pillpalnow_get_remaining_stock($med->ID);
        } else {
            $stock = (int) get_post_meta($med->ID, 'stock_quantity', true);
        }
        $threshold = (int) get_post_meta($med->ID, 'refill_threshold', true);
        $snoozed_until = (int) get_post_meta($med->ID, 'refill_snoozed_until', true);

        if ($threshold > 0 && $stock <= $threshold) {
            if (!$snoozed_until || time() > $snoozed_until) {
                $low_stock_meds[] = array(
                    'id' => $med->ID,
                    'name' => $med->post_title,
                    'member' => $member_name
                );
            }
        }
        // --------------------

        $schedule_type = get_post_meta($med->ID, 'schedule_type', true);
        $dose_times = get_post_meta($med->ID, 'dose_times', true); // array of ['time'=>'08:00', 'dosage'=>'1']
        if (!is_array($dose_times))
            $dose_times = [];

        // Assignment Status Check
        $assignment_status = get_post_meta($med->ID, 'assignment_status', true);
        if (!$assignment_status)
            $assignment_status = 'accepted';
        if ($assignment_status === 'rejected')
            continue;

        // Increment Active Meds Count (Only rejected are hidden)
        $active_meds_count++;

        // --- WEEKLY MEDICATION LOGIC ---
        if ($schedule_type === 'weekly') {
            $selected_weekdays = get_post_meta($med->ID, 'selected_weekdays', true);
            $start_date = get_post_meta($med->ID, 'start_date', true);

            // Calculate NEXT occurrence
            $check_ts = max($current_time, strtotime($start_date));
            $found_date_ts = null;

            // Map our slug days to date('w') integers (0=Sun, 6=Sat)
            $w_map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

            $valid_ws = [];
            if (is_array($selected_weekdays)) {
                foreach ($selected_weekdays as $d) {
                    if (isset($w_map[$d]))
                        $valid_ws[] = $w_map[$d];
                }
            }

            if (!empty($valid_ws)) {
                // Check closest date
                for ($i = 0; $i < 14; $i++) {
                    $test_ts = strtotime("+$i days", $check_ts);
                    $test_date_ts = strtotime(date('Y-m-d', $test_ts));
                    $w_day = (int) date('w', $test_date_ts);

                    if (in_array($w_day, $valid_ws)) {
                        $found_date_ts = $test_date_ts;
                        break;
                    }
                }
            }

            if ($found_date_ts) {
                // Determine display string
                $is_today = (date('Y-m-d', $found_date_ts) === $today_date);
                $day_label = $is_today ? 'Today' : date('D, M j', $found_date_ts);

                // Add to Weekly List
                $weekly_doses[] = array(
                    'med_id' => $med->ID,
                    'med_title' => $med->post_title,
                    'member_name' => $member_name,
                    'next_date_ts' => $found_date_ts,
                    'next_date_display' => $day_label,
                    'dose_times' => $dose_times, // Show all times for that day
                    'weekdays_str' => implode(', ', array_map('ucfirst', $selected_weekdays ?: [])),
                    'is_today' => $is_today
                );
            }
        }

        // --- AS NEEDED LOGIC ---
        if ($schedule_type === 'as_needed') {
            $selected_weekdays = get_post_meta($med->ID, 'selected_weekdays', true);
            $weekdays_str = 'Anytime';
            if (!empty($selected_weekdays) && is_array($selected_weekdays)) {
                $weekdays_str = implode(', ', array_map('ucfirst', $selected_weekdays));
            }

            $as_needed_doses[] = array(
                'med_id' => $med->ID,
                'med_title' => $med->post_title,
                'member_name' => $member_name,
                'restrictions' => $weekdays_str
            );
        }

        // --- DAILY / INTERACTIVE DOSES (Today) ---
        // Includes: Schedule=Daily OR (Weekly IF Today) OR (As Needed IF Today/Valid)
        $should_show_in_daily = false;

        if ($schedule_type === 'daily') {
            $should_show_in_daily = true;
        } elseif ($schedule_type === 'weekly') {
            // Check if today matches selected weekdays
            $selected_weekdays = get_post_meta($med->ID, 'selected_weekdays', true);
            if (is_array($selected_weekdays) && in_array($today_day_slug, $selected_weekdays)) {
                $start_date = get_post_meta($med->ID, 'start_date', true);
                if (!$start_date || $today_date >= $start_date) {
                    $should_show_in_daily = true;
                }
            }
        } elseif ($schedule_type === 'as_needed') {
            // Check if today matches selected weekdays OR empty (meaning allowed always)
            $selected_weekdays = get_post_meta($med->ID, 'selected_weekdays', true);
            if (empty($selected_weekdays) || (is_array($selected_weekdays) && in_array($today_day_slug, $selected_weekdays))) {
                $should_show_in_daily = true;
            }
        }

        if ($should_show_in_daily && is_array($dose_times)) {
            // DEBUG: Log medication processing
            error_log("[COUNTDOWN DEBUG] Processing med: {$med->post_title}, should_show_in_daily=true, dose_times count=" . count($dose_times));

            // Calculate Logs & Adherence for Statistics
            usort($dose_times, function ($a, $b) {
                return strtotime($a['time']) - strtotime($b['time']);
            });

            $logs = get_posts(array(
                'post_type' => 'dose_log',
                'posts_per_page' => -1,
                'meta_query' => array(
                    array('key' => 'medication_id', 'value' => $med->ID),
                    array('key' => 'log_date', 'value' => $today_date)
                )
            ));

            $taken_logs = [];
            $postponed_logs = [];
            $skipped_logs = []; // Track skipped for stats

            // ✅ DASHBOARD DISPLAY RULE: Only show active statuses (pending, postponed)
            // Exclude: taken, skipped, superseded
            $active_logs_count = 0;

            foreach ($logs as $l) {
                $s = get_post_meta($l->ID, 'status', true);

                // Explicitly ignore superseded logs for counting
                if ($s === 'superseded')
                    continue;

                $active_logs_count++;

                if ($s === 'taken') {
                    $taken_logs[] = $l;
                } elseif ($s === 'skipped') {
                    $skipped_logs[] = $l;
                } elseif ($s === 'postponed') {
                    // ✅ SINGLE ACTIVE POSTPONE: Exclude superseded postponed entries
                    // Ensure we double-check just in case (though 'superseded' status check above handles it)
                    $superseded_at = get_post_meta($l->ID, 'superseded_at', true);
                    if (!$superseded_at) {
                        $postponed_logs[] = $l;
                    }
                }
            }

            $med_taken_count = count($taken_logs); // Strict count: only 'taken'

            // Percentage is now handled at the member level using centralized logic
            $total_scheduled_med = count($dose_times);

            // Determine which slots act as "Upcoming"
            // ANY log (Taken, Skipped, Postponed) accounts for a scheduled slot
            // NEW: Track Consumed Indices for Strict Filtering
            // We map which specific 'dose_index' from the schedule have been fulfilled/postponed
            $consumed_indices = [];
            $all_active_logs = array_merge($taken_logs, $skipped_logs, $postponed_logs);

            foreach ($all_active_logs as $l) {
                // If the log has a saved dose_index, use it
                $idx = get_post_meta($l->ID, 'dose_index', true);
                if ($idx !== '' && $idx !== false) {
                    $consumed_indices[] = (int) $idx;
                }
            }

            // ✅ Add ONLY Active Postponed Doses to Display List
            foreach ($postponed_logs as $pl) {
                $p_ts = (int) get_post_meta($pl->ID, 'postponed_until', true);
                $p_idx = get_post_meta($pl->ID, 'dose_index', true); // Retrieve index

                if ($p_ts > 0 && date('Y-m-d', $p_ts) === $today_date) {
                    $instructions = get_post_meta($med->ID, 'instructions', true);

                    // Determine availability (consistent with scheduled doses)
                    $is_available = ($p_ts <= $current_time + (2 * 3600));

                    $daily_doses[] = array(
                        'ts' => $p_ts,
                        'time_display' => date('g:i A', $p_ts),
                        'med_title' => $med->post_title,
                        'dosage' => '1 pill', // Fallback as we can't easily map back to schedule without index logic matching
                        'member_name' => $member_name,
                        'med_id' => $med->ID,
                        'dose_index' => ($p_idx !== '' && $p_idx !== false) ? (int) $p_idx : -1, // Pass index
                        'is_user' => $is_me,
                        'assignment_status' => $assignment_status,
                        'instructions' => $instructions,
                        'is_available' => $is_available,
                        // Tag for styling
                        'is_weekly_schedule' => ($schedule_type === 'weekly'),
                        'is_as_needed' => ($schedule_type === 'as_needed'),
                        'is_postponed_item' => true
                    );

                    if ($p_ts > $current_time) {
                        if ($next_dose_timestamp === null || $p_ts < $next_dose_timestamp) {
                            $next_dose_timestamp = $p_ts;
                        }
                    }
                }
            }

            // If As Needed, we might show "available" slots, but As Needed might not have specific logged slots logic 
            // in the same rigid way. But we're treating dose_times as the "Available Times" to take it.
            // in the same rigid way. But our `page-add-medication` enforces adding at least one time. So we assume times exist.

            foreach ($dose_times as $sched_idx => $dt) {
                $time_str = $dt['time'];

                // Calculate timestamp for today
                $dose_ts_today = strtotime($today_date . ' ' . $time_str);

                // CHECK CONSUMED STATUS FIRST - skip consumed doses entirely
                // (they shouldn't count for countdown OR display)
                if (in_array($sched_idx, $consumed_indices)) {
                    error_log("[COUNTDOWN DEBUG] Med: {$med->post_title}, Index: $sched_idx - SKIPPED (consumed)");
                    continue;
                }

                // DEBUG: Log timestamp calculation
                error_log("[COUNTDOWN DEBUG] Med: {$med->post_title}, Index: $sched_idx, Time: $time_str, Today TS: $dose_ts_today, Current: $current_time");

                // For countdown timer: ONLY consider TODAY's doses that are in the future AND NOT consumed
                if ($dose_ts_today > $current_time) {
                    if ($next_dose_timestamp === null || $dose_ts_today < $next_dose_timestamp) {
                        $next_dose_timestamp = $dose_ts_today;
                        error_log("[COUNTDOWN DEBUG] ✅ Updated next_dose_timestamp to $next_dose_timestamp for {$med->post_title} at $time_str");
                    }
                }

                // Use today's timestamp for display
                $dose_ts = $dose_ts_today;

                $instructions = get_post_meta($med->ID, 'instructions', true);
                // Determine if "Take Now" should be visible
                // We only show it if the dose is in the past (Missed/Due) OR within a 2-hour window of the future.
                $is_available = false;
                if ($dose_ts <= $current_time + (2 * 3600)) {
                    $is_available = true;
                }

                $daily_doses[] = array(
                    'ts' => $dose_ts,
                    'time_display' => date('g:i A', $dose_ts),
                    'med_title' => $med->post_title,
                    'dosage' => $dt['dosage'] . ' pill',
                    'member_name' => $member_name,
                    'med_id' => $med->ID,
                    'dose_index' => $sched_idx, // Pass index
                    'is_user' => $is_me,
                    'assignment_status' => $assignment_status,
                    'instructions' => $instructions,
                    'is_available' => $is_available, // Pass this to the frontend
                    // Tag for styling
                    'is_weekly_schedule' => ($schedule_type === 'weekly'),
                    'is_as_needed' => ($schedule_type === 'as_needed')
                );
            }
        }

        // --- LOOKAHEAD FOR TIMER: Check Tomorrow's Doses ---
        // Even if not shown in "Today's Schedule", we need them for the countdown if today is done.
        $check_tomorrow = false;
        if ($schedule_type === 'daily') {
            $check_tomorrow = true;
        } elseif ($schedule_type === 'weekly') {
            $selected_weekdays = get_post_meta($med->ID, 'selected_weekdays', true);
            // Get tomorrow's day slug
            $tomorrow_ts = $current_time + 86400;
            $tomorrow_slug = strtolower(date('D', $tomorrow_ts));

            if (is_array($selected_weekdays) && in_array($tomorrow_slug, $selected_weekdays)) {
                $start_date = get_post_meta($med->ID, 'start_date', true);
                $tomorrow_date = date('Y-m-d', $tomorrow_ts);
                if (!$start_date || $tomorrow_date >= $start_date) {
                    $check_tomorrow = true;
                }
            }
        }

        if ($check_tomorrow && is_array($dose_times)) {
            $tomorrow_date = date('Y-m-d', $current_time + 86400);
            foreach ($dose_times as $dt) {
                // Calculate timestamp for tomorrow
                $dose_ts_tomorrow = strtotime($tomorrow_date . ' ' . $dt['time']);

                // It is definitely in the future relative to now
                if ($next_dose_timestamp === null || $dose_ts_tomorrow < $next_dose_timestamp) {
                    $next_dose_timestamp = $dose_ts_tomorrow;
                }
            }
        }
    }

    // Stats: Use Centralized Logic
    if (class_exists('PillPalNow_Data_Validator')) {
        $daily_stats = PillPalNow_Data_Validator::get_daily_stats($current_user_id, $today_date, $member_id);
    } else {
        $daily_stats = array('percentage' => 0, 'total_scheduled' => $total_scheduled, 'taken_count' => 0);
    }
    $percentage = isset($daily_stats['percentage']) ? $daily_stats['percentage'] : 0;
    $family_stats[] = array(
        'name' => $member_name,
        'percentage' => $percentage,
        'id' => $member_id,
        'total_scheduled' => isset($daily_stats['total_scheduled']) ? $daily_stats['total_scheduled'] : 0
    );
}

// Sort Daily Doses
usort($daily_doses, function ($a, $b) {
    return $a['ts'] - $b['ts'];
});

// Sort Weekly Doses (by Next Date)
usort($weekly_doses, function ($a, $b) {
    return $a['next_date_ts'] - $b['next_date_ts'];
});


// Timer Logic
error_log("[COUNTDOWN DEBUG] Final next_dose_timestamp: " . ($next_dose_timestamp ?: 'NULL'));
$time_diff_display = "-- h -- m";
$hero_subtitle = "Until Next Dose";

if ($active_meds_count === 0) {
    // 1. No Meds (New User / Guest / Deleted)
    $time_diff_display = "Welcome";
    $hero_subtitle = "Add your first medication";
} elseif ($next_dose_timestamp) {
    // 2. Active Doses Upcoming
    $diff = $next_dose_timestamp - $current_time;
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $time_diff_display = sprintf('%dh %02dm', $hours, $minutes);
    $hero_subtitle = "Until Next Dose";
} else {
    // 3. Meds exist but none due today (All Done)
    $time_diff_display = "All Done";
    $hero_subtitle = "For Today";
}

get_header();
?>

<?php
// Display error messages
if (isset($_GET['error'])):
    $error_message = '';
    if ($_GET['error'] === 'unauthorized') {
        $error_message = 'Unauthorized action: You cannot perform actions on medications not assigned to you.';
    } elseif ($_GET['error'] === 'already_taken') {
        $error_message = 'One action per dose allowed. This dose has already been logged.';
    }

    if ($error_message):
        ?>
        <div class="error-container p-4"
            style="background: rgba(220, 38, 38, 0.1); border-bottom: 1px solid rgba(220, 38, 38, 0.2); position: sticky; top: 0; z-index: 1000;">
            <div class="flex items-center gap-3 max-w-lg mx-auto">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    class="text-red-400">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <p class="text-red-400 font-semibold"><?php echo esc_html($error_message); ?></p>
            </div>
        </div>
        <?php
    endif;
endif;
?>

<div class="app-container flex-col" style="min-height: 100vh; padding-bottom: 80px;">

    <div class="container p-4 flex flex-col gap-6">

        <!-- App Header (Greeting) -->
        <header class="app-header mb-2">
            <div>
                <p class="text-xs text-secondary font-semibold uppercase tracking-wider">
                    <?php echo 'Today, ' . date('M j'); ?>
                </p>
                <?php
                // Determine greeting based on current hour
                $hour = (int) current_time('G'); // 24-hour format, 0-23
                if ($hour >= 5 && $hour < 12) {
                    $greeting = 'Good Morning';
                } elseif ($hour >= 12 && $hour < 17) {
                    $greeting = 'Good Afternoon';
                } else {
                    $greeting = 'Good Evening';
                }
                ?>
                <h1 class="text-xl font-bold">
                    <?php echo esc_html($greeting); ?>,
                    <?php echo esc_html(wp_get_current_user()->display_name ?: 'Guest'); ?>
                </h1>
            </div>
        </header>

        <!-- Top Section: Grid -->
        <div class="dashboard-grid">

            <!-- 1. Next Dose Timer (Hero) -->
            <div class="dashboard-card hero-card">
                <div class="hero-glow"></div>
                <!-- Pass UTC timestamp * 1000 for JS -->
                <h2 class="hero-timer-text"
                    data-target-ts="<?php echo $next_dose_timestamp ? ($next_dose_timestamp * 1000) : ''; ?>">
                    <?php echo esc_html($time_diff_display); ?>
                </h2>
                <p class="hero-subtitle">
                    <?php echo esc_html($hero_subtitle); ?>
                </p>
            </div>

            <!-- 2. Family Status (Hidden for Family Members) -->
            <?php if (!$is_family_member_role): ?>
                <div class="dashboard-card family-card">
                    <h3 class="card-title">Family Status</h3>
                    <div class="family-list">
                        <?php foreach ($family_stats as $stat):
                            // Color logic: Gray if no schedule, otherwise Green/Yellow/Red
                            if ($stat['total_scheduled'] == 0) {
                                $color_class = 'text-secondary';
                            } else {
                                $color_class = $stat['percentage'] >= 80 ? 'text-success' : ($stat['percentage'] >= 50 ? 'text-warning' : 'text-danger');
                            }
                            ?>
                            <?php
                            // Determine link: Self gets a special view param, others get their permalink
                            $link = ($stat['id'] == 0) ? add_query_arg('view', 'self', get_permalink()) : get_permalink($stat['id']);
                            ?>
                            <div class="family-item" onclick="window.location='<?php echo esc_url($link); ?>'">
                                <div class="flex items-center gap-3">
                                    <div class="avatar-circle">
                                        <?php echo strtoupper(substr($stat['name'], 0, 1)); ?>
                                    </div>
                                    <span class="family-name"><?php echo esc_html($stat['name']); ?></span>
                                </div>
                                <span
                                    class="family-percent <?php echo $color_class; ?>"><?php echo $stat['percentage']; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                        <a href="<?php echo home_url('/add-family-member'); ?>" class="add-member-link">+ Add Member</a>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- Weekly Medicines Section -->
        <?php if (!empty($weekly_doses)): ?>
            <div class="weekly-section">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="card-title mb-0">Weekly Medicines</h3>
                </div>
                <div class="flex gap-3 overflow-x-auto pb-2 no-scrollbar">
                    <?php foreach ($weekly_doses as $wd): ?>
                        <div
                            class="dashboard-card p-4 min-w-[200px] flex-shrink-0 relative <?php echo $wd['is_today'] ? 'border-primary bg-primary/5' : ''; ?>">
                            <div class="flex flex-col gap-1">
                                <span class="text-xs font-bold text-secondary uppercase tracking-wider">
                                    <?php echo esc_html($wd['weekdays_str']); ?>
                                </span>
                                <h4 class="font-bold text-white text-lg leading-tight truncate">
                                    <?php echo esc_html($wd['med_title']); ?>
                                </h4>
                                <p class="text-xs text-secondary mb-2">
                                    For <?php echo esc_html($wd['member_name']); ?>
                                </p>

                                <div class="mt-2 pt-2 border-t border-gray-800">
                                    <span class="text-xs text-gray-400">Next Due:</span>
                                    <div class="font-bold <?php echo $wd['is_today'] ? 'text-primary' : 'text-white'; ?>">
                                        <?php echo esc_html($wd['next_date_display']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- As Needed Medicines Section (New) -->
        <?php if (!empty($as_needed_doses)): ?>
            <div class="as-needed-section">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="card-title mb-0">As Needed Medicines</h3>
                </div>
                <div class="flex gap-3 overflow-x-auto pb-2 no-scrollbar">
                    <?php foreach ($as_needed_doses as $and): ?>
                        <div class="dashboard-card p-4 min-w-[200px] flex-shrink-0 relative border-l-4 border-l-blue-400">
                            <div class="flex flex-col gap-1">
                                <span class="text-xs font-bold text-secondary uppercase tracking-wider">
                                    <?php echo esc_html($and['restrictions']); ?>
                                </span>
                                <h4 class="font-bold text-white text-lg leading-tight truncate">
                                    <?php echo esc_html($and['med_title']); ?>
                                </h4>
                                <p class="text-xs text-secondary mb-2">
                                    For <?php echo esc_html($and['member_name']); ?>
                                </p>
                                <div class="mt-2 pt-2 border-t border-gray-800">
                                    <span class="text-xs text-green-400 font-bold">Available Now</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Bottom Section: Upcoming Meds (Daily + Today's Weekly/AsNeeded) -->
        <div>
            <div class="flex justify-between items-center mb-4">
                <h3 class="card-title mb-0">Today's Schedule</h3>
                <a href="<?php echo home_url('/manage-medications'); ?>"
                    class="text-sm text-primary hover:text-blue-400 font-semibold" style="text-decoration: none;">Manage
                    All</a>
            </div>

            <?php if (empty($daily_doses)): ?>
                <div class="dashboard-card p-4 text-center">
                    <p class="text-secondary">No upcoming doses for today.</p>
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-3">
                    <?php
                    // Group doses by timestamp for navigation
                    $doses_by_time = array();
                    foreach ($daily_doses as $idx => $dose) {
                        $time_key = $dose['ts'];
                        if (!isset($doses_by_time[$time_key])) {
                            $doses_by_time[$time_key] = array();
                        }
                        $doses_by_time[$time_key][] = $idx;
                    }

                    foreach ($daily_doses as $idx => $dose):
                        $card_class = $dose['is_user'] ? 'is-user' : 'is-family';
                        // Add visual hints
                        if (isset($dose['is_weekly_schedule']) && $dose['is_weekly_schedule'])
                            $card_class .= ' border-l-4 border-l-purple-500';
                        elseif (isset($dose['is_as_needed']) && $dose['is_as_needed'])
                            $card_class .= ' border-l-4 border-l-blue-400';
                        elseif (isset($dose['is_postponed_item']) && $dose['is_postponed_item'])
                            $card_class .= ' border-l-4 border-l-yellow-500 opacity-90';

                        // Check if there are multiple medicines at this time
                        $same_time_doses = $doses_by_time[$dose['ts']];
                        $has_multiple = count($same_time_doses) > 1;
                        $current_position = array_search($idx, $same_time_doses) + 1;
                        $total_at_time = count($same_time_doses);
                        ?>
                        <!-- Card + Inline Panel Container -->
                        <div class="inline-panel-container" data-dose-idx="<?php echo $idx; ?>"
                            data-timestamp="<?php echo $dose['ts']; ?>">
                            <!-- Medicine Card -->
                            <div class="dashboard-card med-item-card <?php echo $card_class; ?>">
                                <div class="flex items-center gap-4">
                                    <div class="med-icon">💊</div>
                                    <div>
                                        <h4 class="med-title"><?php echo esc_html($dose['med_title']); ?></h4>
                                        <p class="med-meta">
                                            <?php echo esc_html($dose['dosage']); ?> •
                                            <?php echo esc_html($dose['time_display']); ?>
                                            <span class="med-member-badge">•
                                                <?php echo esc_html($dose['member_name']); ?></span>
                                            <?php if (isset($dose['is_as_needed']) && $dose['is_as_needed']): ?>
                                                <span class="text-xs text-blue-300 ml-1">(As Needed)</span>
                                            <?php endif; ?>
                                            <?php if (isset($dose['is_postponed_item']) && $dose['is_postponed_item']): ?>
                                                <span
                                                    class="text-xs text-yellow-300 ml-1 uppercase font-bold tracking-wider">(Postponed)</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-2">
                                    <?php if ($dose['assignment_status'] === 'pending' && $dose['is_user']): ?>
                                        <div class="flex gap-2">
                                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=pillpalnow_update_med_status&status=accepted&med_id=' . $dose['med_id'] . '&nonce=' . wp_create_nonce('pillpalnow_med_status_nonce'))); ?>"
                                                class="btn btn-sm btn-primary px-3 py-1 text-xs">Accept</a>
                                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=pillpalnow_update_med_status&status=rejected&med_id=' . $dose['med_id'] . '&nonce=' . wp_create_nonce('pillpalnow_med_status_nonce'))); ?>"
                                                class="btn btn-sm btn-secondary px-3 py-1 text-xs text-danger border-danger">Reject</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center gap-2">
                                            <?php if ($dose['is_available']): ?>
                                                <button type="button" class="action-btn btn-check open-inline-panel"
                                                    data-dose-idx="<?php echo $idx; ?>" title="Take">
                                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                </button>
                                                <button type="button" class="action-btn btn-skip open-inline-panel"
                                                    data-dose-idx="<?php echo $idx; ?>" title="Skip">
                                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                        stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <line x1="18" y1="6" x2="6" y2="18"></line>
                                                        <line x1="6" y1="6" x2="18" y2="18"></line>
                                                    </svg>
                                                </button>
                                            <?php else: ?>
                                                <span
                                                    class="text-secondary text-xs font-medium bg-white/5 px-2 py-1 rounded">Upcoming</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Inline Panel (Hidden by default) -->
                            <div class="inline-panel collapsed" data-dose-idx="<?php echo $idx; ?>">
                                <div class="inline-panel-content">
                                    <!-- Close Button -->
                                    <button class="inline-panel-close" data-dose-idx="<?php echo $idx; ?>">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2">
                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                        </svg>
                                    </button>

                                    <!-- Navigation for multiple medicines at same time -->
                                    <?php if ($has_multiple): ?>
                                        <div class="inline-panel-nav">
                                            <button type="button" class="nav-prev-btn" data-timestamp="<?php echo $dose['ts']; ?>"
                                                data-current-idx="<?php echo $idx; ?>">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2">
                                                    <polyline points="15 18 9 12 15 6"></polyline>
                                                </svg>
                                            </button>
                                            <span class="nav-pagination"><?php echo $current_position; ?> of
                                                <?php echo $total_at_time; ?></span>
                                            <button type="button" class="nav-next-btn" data-timestamp="<?php echo $dose['ts']; ?>"
                                                data-current-idx="<?php echo $idx; ?>">
                                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2">
                                                    <polyline points="9 18 15 12 9 6"></polyline>
                                                </svg>
                                            </button>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Medicine Info Header -->
                                    <div class="inline-panel-header">
                                        <div class="inline-panel-icon">💊</div>
                                        <div>
                                            <h4 class="inline-panel-title"><?php echo esc_html($dose['med_title']); ?></h4>
                                            <p class="inline-panel-subtitle">
                                                <?php echo esc_html($dose['dosage']); ?> •
                                                <?php echo esc_html($dose['time_display']); ?>
                                            </p>
                                            <p class="inline-panel-member">For <?php echo esc_html($dose['member_name']); ?></p>
                                        </div>
                                    </div>

                                    <!-- Instructions -->
                                    <?php if (!empty($dose['instructions'])): ?>
                                        <div class="inline-panel-instructions">
                                            <h5 class="instructions-label">Instructions</h5>
                                            <p class="instructions-text"><?php echo esc_html($dose['instructions']); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Form -->
                                    <form class="inline-panel-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        method="post">
                                        <input type="hidden" name="action" value="pillpalnow_log_dose">
                                        <?php wp_nonce_field('pillpalnow_dose_log_action', 'pillpalnow_dose_log_nonce'); ?>
                                        <input type="hidden" name="medication_id" value="<?php echo $dose['med_id']; ?>">
                                        <input type="hidden" name="date" value="<?php echo date('Y-m-d', $dose['ts']); ?>">
                                        <input type="hidden" name="time" value="<?php echo date('g:i A', $dose['ts']); ?>">
                                        <input type="hidden" name="dose_index"
                                            value="<?php echo isset($dose['dose_index']) ? $dose['dose_index'] : ''; ?>">
                                        <input type="hidden" name="target_timestamp" value="<?php echo $dose['ts']; ?>">
                                        <input type="hidden" name="postpone_time_ts" class="postpone-ts-field">

                                        <!-- Notes -->
                                        <div class="inline-panel-notes">
                                            <label for="notes-<?php echo $idx; ?>" class="notes-label">Optional Notes</label>
                                            <textarea name="notes" id="notes-<?php echo $idx; ?>" class="notes-textarea"
                                                rows="2" placeholder="Add optional notes..."></textarea>
                                        </div>

                                        <!-- Main Actions -->
                                        <div class="inline-panel-actions-main">
                                            <?php if ($dose['is_available']): ?>
                                                <button type="submit" name="status" value="taken"
                                                    class="btn btn-primary w-full py-3 text-lg font-bold flex items-center justify-center gap-2"
                                                    style="background-color: var(--success-color);">
                                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="3">
                                                        <polyline points="20 6 9 17 4 12"></polyline>
                                                    </svg>
                                                    Taken
                                                </button>

                                                <div class="grid grid-cols-2 gap-3 mt-3">
                                                    <button type="submit" name="status" value="skipped"
                                                        class="btn btn-secondary border border-warning/30 text-warning hover:bg-warning/10 w-full py-2 font-semibold">
                                                        Skip
                                                    </button>
                                                    <button type="button"
                                                        class="btn-show-postpone btn btn-secondary border border-gray-700 w-full py-2 font-semibold">
                                                        Postpone
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <div class="w-full py-4 text-center bg-white/5 rounded-xl border border-white/10">
                                                    <p class="text-secondary font-medium mb-1">Upcoming</p>
                                                    <p class="text-xs text-secondary/60">Scheduled for
                                                        <?php echo esc_html($dose['time_display']); ?>
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Postpone Options (Initially Hidden) -->
                                        <div class="inline-panel-actions-postpone hidden">
                                            <p class="text-white text-center font-bold text-sm mb-3">Postpone duration</p>

                                            <div class="grid grid-cols-2 gap-2">
                                                <button type="button" class="btn-postpone-opt" data-minutes="15">15 min</button>
                                                <button type="button" class="btn-postpone-opt" data-minutes="30">30 min</button>
                                                <button type="button" class="btn-postpone-opt" data-minutes="60">1 hour</button>
                                                <button type="button" class="btn-postpone-opt btn-custom-time">Custom</button>
                                            </div>

                                            <div class="custom-time-container hidden mt-3">
                                                <label class="text-xs text-secondary mb-1 block">Pick Date & Time</label>
                                                <input type="datetime-local" class="custom-date-time-input">
                                            </div>

                                            <div class="flex flex-col gap-2 mt-3">
                                                <button type="submit" name="status" value="postponed"
                                                    class="btn-confirm-postpone btn btn-primary w-full" disabled>
                                                    Confirm Postpone
                                                </button>
                                                <button type="button"
                                                    class="btn-cancel-postpone text-secondary text-xs text-center hover:text-white underline">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Inline Panel Logic Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Get all inline panel triggers
            const triggers = document.querySelectorAll('.open-inline-panel');
            const closeBtns = document.querySelectorAll('.inline-panel-close');

            // Build timestamp to indices map for navigation
            const timestampMap = {};
            document.querySelectorAll('.inline-panel-container').forEach((container) => {
                const timestamp = container.dataset.timestamp;
                const idx = parseInt(container.dataset.doseIdx);
                if (!timestampMap[timestamp]) {
                    timestampMap[timestamp] = [];
                }
                timestampMap[timestamp].push(idx);
            });

            // Open inline panel
            triggers.forEach(trigger => {
                trigger.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const doseIdx = this.dataset.doseIdx;

                    // Close all other panels first
                    closeAllPanels();

                    // Open this panel
                    openPanel(doseIdx);
                });
            });

            // Close buttons
            closeBtns.forEach(btn => {
                btn.addEventListener('click', function () {
                    const doseIdx = this.dataset.doseIdx;
                    closePanel(doseIdx);
                });
            });

            function openPanel(doseIdx) {
                const panel = document.querySelector(`.inline-panel[data-dose-idx="${doseIdx}"]`);
                if (panel) {
                    panel.classList.remove('collapsed');
                    panel.classList.add('expanded');

                    // Scroll into view smoothly
                    setTimeout(() => {
                        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }, 100);
                }
            }

            function closePanel(doseIdx) {
                const panel = document.querySelector(`.inline-panel[data-dose-idx="${doseIdx}"]`);
                if (panel) {
                    panel.classList.remove('expanded');
                    panel.classList.add('collapsed');
                }
            }

            function closeAllPanels() {
                document.querySelectorAll('.inline-panel.expanded').forEach(panel => {
                    panel.classList.remove('expanded');
                    panel.classList.add('collapsed');
                });
            }

            // Navigation between medicines at same time
            document.querySelectorAll('.nav-next-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const timestamp = this.dataset.timestamp;
                    const currentIdx = parseInt(this.dataset.currentIdx);
                    const indices = timestampMap[timestamp];
                    const currentPos = indices.indexOf(currentIdx);
                    const nextPos = (currentPos + 1) % indices.length; // Loop around
                    const nextIdx = indices[nextPos];

                    closePanel(currentIdx);
                    openPanel(nextIdx);
                });
            });

            document.querySelectorAll('.nav-prev-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const timestamp = this.dataset.timestamp;
                    const currentIdx = parseInt(this.dataset.currentIdx);
                    const indices = timestampMap[timestamp];
                    const currentPos = indices.indexOf(currentIdx);
                    const prevPos = (currentPos - 1 + indices.length) % indices.length; // Loop around
                    const prevIdx = indices[prevPos];

                    closePanel(currentIdx);
                    openPanel(prevIdx);
                });
            });

            // Postpone logic for each panel
            document.querySelectorAll('.inline-panel').forEach(panel => {
                const mainActions = panel.querySelector('.inline-panel-actions-main');
                const postponeActions = panel.querySelector('.inline-panel-actions-postpone');
                const btnShowPostpone = panel.querySelector('.btn-show-postpone');
                const btnCancelPostpone = panel.querySelector('.btn-cancel-postpone');
                const postponeOpts = panel.querySelectorAll('.btn-postpone-opt');
                const customTimeContainer = panel.querySelector('.custom-time-container');
                const customDateTimeInput = panel.querySelector('.custom-date-time-input');
                const btnConfirmPostpone = panel.querySelector('.btn-confirm-postpone');
                const postponeTsInput = panel.querySelector('.postpone-ts-field');

                if (!btnShowPostpone) return; // Skip if elements don't exist

                function resetPostponeView() {
                    mainActions.classList.remove('hidden');
                    postponeActions.classList.add('hidden');
                    postponeTsInput.value = '';
                    customTimeContainer.classList.add('hidden');
                    btnConfirmPostpone.disabled = true;

                    // Reset button styles
                    postponeOpts.forEach(btn => {
                        btn.classList.remove('selected');
                        btn.style.backgroundColor = '#374151';
                        btn.style.color = '#d1d5db';
                    });
                }

                btnShowPostpone.addEventListener('click', () => {
                    mainActions.classList.add('hidden');
                    postponeActions.classList.remove('hidden');
                });

                btnCancelPostpone.addEventListener('click', () => {
                    resetPostponeView();
                });

                postponeOpts.forEach(opt => {
                    opt.addEventListener('click', function () {
                        // Reset all buttons
                        postponeOpts.forEach(b => {
                            b.classList.remove('selected');
                            b.style.backgroundColor = '#374151';
                            b.style.color = '#d1d5db';
                        });

                        // Set selected style
                        this.classList.add('selected');
                        this.style.backgroundColor = '#3b82f6';
                        this.style.color = '#ffffff';

                        const metaMins = this.dataset.minutes;
                        const isCustom = this.classList.contains('btn-custom-time');

                        if (isCustom) {
                            customTimeContainer.classList.remove('hidden');
                            btnConfirmPostpone.disabled = true;
                        } else {
                            customTimeContainer.classList.add('hidden');
                            const now = new Date();
                            const pTime = new Date(now.getTime() + parseInt(metaMins) * 60000);
                            const ts = Math.floor(pTime.getTime() / 1000);
                            postponeTsInput.value = ts;
                            btnConfirmPostpone.disabled = false;
                        }
                    });
                });

                customDateTimeInput.addEventListener('change', function () {
                    if (this.value) {
                        const d = new Date(this.value);
                        const ts = Math.floor(d.getTime() / 1000);
                        postponeTsInput.value = ts;
                        btnConfirmPostpone.disabled = false;
                    } else {
                        btnConfirmPostpone.disabled = true;
                    }
                });
            });
        });
    </script>
</div>

<?php get_footer(); ?>