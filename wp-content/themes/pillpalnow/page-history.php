<?php
/**
 * Template Name: History Page
 *
 * @package PillPalNow
 */

// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

get_header('simple');

// PERMISSION CHECK
if (class_exists('PillPalNow_Permissions') && !PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_VIEW_HISTORY, true)) {
    ?>
    <div class="app-container flex-col justify-start" style="min-height: 100vh;">
        <div class="container p-6">
            <div class="bg-red-900/50 border border-red-700/50 text-red-200 px-4 py-3 rounded-lg relative" role="alert">
                <strong class="font-bold">Access Restricted</strong>
                <span class="block sm:inline"> You do not have permission to view history logs.</span>
            </div>
            <a href="<?php echo home_url('/dashboard'); ?>"
                class="btn bg-gray-800 text-white mt-4 inline-block px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors">
                &larr; Back to Dashboard
            </a>
        </div>
    </div>
    <?php
    get_footer();
    exit;
}

$current_user_id = get_current_user_id();

// --- 1. Get Filters ---
$filter_member = isset($_GET['member']) ? sanitize_text_field($_GET['member']) : 'all'; // 'all', 'me', or family_member_id
$filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all'; // 'all', 'taken', 'missed', 'skipped'
$selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
$selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m'); // Format YYYY-MM

// Parse Date
$ts_date = strtotime($selected_date);
$ts_month = strtotime($selected_month . '-01');
$month_start = date('Y-m-01', $ts_month);
$month_end = date('Y-m-t', $ts_month);

// --- 2. Fetch Family Members ---
$args_fam = array(
    'post_type' => 'family_member',
    'posts_per_page' => -1,
    'author' => $current_user_id,
);
$family_members = get_posts($args_fam);

// --- 3. Helper Functions (Inline for now) ---

/**
 * Get relevant medications based on member filter
 */
/**
 * Get relevant medications based on member filter
 */
function get_medications_for_history($user_id, $filter_member, $family_members_list = [])
{
    $args = array(
        'post_type' => 'medication',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'author' => $user_id, // Only medications authored by current user
    );

    $meta_query = array('relation' => 'OR');

    // Case 1: "Me" (Strictly my assigned meds)
    if ($filter_member === 'me') {
        $meta_query[] = array('key' => 'assigned_user_id', 'value' => $user_id);
    }
    // Case 2: Specific Family Member
    elseif (is_numeric($filter_member)) {
        // Security: Verify this member belongs to user
        $is_owned = false;
        foreach ($family_members_list as $fm) {
            if ($fm->ID == $filter_member) {
                $is_owned = true;
                break;
            }
        }

        if ($is_owned) {
            $meta_query[] = array('key' => 'family_member_id', 'value' => $filter_member);
        } else {
            return []; // Unauthorized access or invalid ID
        }
    }
    // Case 3: "All" (Me + My Managed Family)
    else {
        // 1. My Meds
        $meta_query[] = array('key' => 'assigned_user_id', 'value' => $user_id);

        // 2. My Family's Meds
        if (!empty($family_members_list)) {
            $fam_ids = array_map(function ($f) {
                return $f->ID;
            }, $family_members_list);
            $meta_query[] = array('key' => 'family_member_id', 'value' => $fam_ids, 'compare' => 'IN');
        }
    }

    $args['meta_query'] = $meta_query;
    return get_posts($args);
}

/**
 * Get Dose Logs for a date range
 */
function get_dose_logs_range($medication_ids, $start_date, $end_date)
{
    if (empty($medication_ids))
        return [];

    $args = array(
        'post_type' => 'dose_log',
        'posts_per_page' => -1,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => 'log_date',
                'value' => array($start_date, $end_date),
                'compare' => 'BETWEEN',
                'type' => 'DATE'
            ),
            array(
                'key' => 'medication_id',
                'value' => $medication_ids,
                'compare' => 'IN'
            )
        )
    );
    return get_posts($args);
}

// Fetch Data
$medications = get_medications_for_history($current_user_id, $filter_member, $family_members);

$med_ids = array_map(function ($m) {
    return $m->ID;
}, $medications);
$logs_month = get_dose_logs_range($med_ids, $month_start, $month_end);

// --- 4. Process Data for Calendar (Adherence Calculation) ---
// Structure: $calendar_data[ 'Y-m-d' ] = [ 'scheduled' => 5, 'taken' => 3, 'missed' => 2, 'status' => 'partial' ]
$calendar_data = [];

// Create a map of logs for faster visual lookup: $log_map[date][med_id][time] => status
$log_map = [];
foreach ($logs_month as $log) {
    $l_date = get_post_meta($log->ID, 'log_date', true);
    $l_med = get_post_meta($log->ID, 'medication_id', true);

    if (!isset($log_map[$l_date]))
        $log_map[$l_date] = [];
    if (!isset($log_map[$l_date][$l_med]))
        $log_map[$l_date][$l_med] = [];

    // Check duplication (if user logged same med multiple times same day?)
    // Just store all logs
    $log_map[$l_date][$l_med][] = $log;
}

// Iterate each day of month to calculate status
$period = new DatePeriod(
    new DateTime($month_start),
    new DateInterval('P1D'),
    (new DateTime($month_end))->modify('+1 day')
);

foreach ($period as $dt) {
    $date_str = $dt->format('Y-m-d');
    $w_day_slug = strtolower($dt->format('D')); // mon, tue, etc.

    $total_scheduled = 0;
    $total_taken = 0;

    // For each medication, how many doses are scheduled today?
    foreach ($medications as $med) {
        $meta = get_post_meta($med->ID);
        $s_type = isset($meta['schedule_type'][0]) ? $meta['schedule_type'][0] : 'daily';

        // --- FILTER 1: Start Date ---
        // Avoid "Missed" for days before the med started.
        $start_date = isset($meta['start_date'][0]) ? $meta['start_date'][0] : '';
        // Fallback to post_date if start_date missing
        if (!$start_date) {
            $start_date = date('Y-m-d', strtotime($med->post_date));
        }

        if ($date_str < $start_date) {
            continue; // Not active yet
        }

        // --- FILTER 2: Schedule Type ---
        $is_scheduled_day = false;

        if ($s_type === 'daily') {
            $is_scheduled_day = true;
        } elseif ($s_type === 'weekly') {
            $sel_days = isset($meta['selected_weekdays'][0]) ? maybe_unserialize($meta['selected_weekdays'][0]) : [];
            if (is_array($sel_days) && in_array($w_day_slug, $sel_days)) {
                $is_scheduled_day = true;
            }
        } elseif ($s_type === 'as_needed') {
            // As Needed: Only count if taken? Or don't count as "Scheduled" to avoid "Missed".
            // We usually don't track "Adherence" for As Needed unless we have a specific target.
            // Let's Skip adherence calc for As Needed to be safe.
            $is_scheduled_day = false;
        }

        if (!$is_scheduled_day) {
            continue;
        }

        $dose_times = isset($meta['dose_times'][0]) ? maybe_unserialize($meta['dose_times'][0]) : [];
        if (!is_array($dose_times))
            $dose_times = [];

        $med_scheduled_count = count($dose_times);
        $total_scheduled += $med_scheduled_count;

        // Count Taken logs
        $logs_for_med = isset($log_map[$date_str][$med->ID]) ? $log_map[$date_str][$med->ID] : [];

        // Count valid logs (taken or skipped)
        // We cap at scheduled count to simply adherence calc (unless we want to support extra doses?)
        // For adherence UI: if scheduled 2, and logged 2, it's green.
        $valid_logs = 0;
        foreach ($logs_for_med as $l_obj) {
            // just count existence as "handled"
            $valid_logs++;
        }

        if ($valid_logs > $med_scheduled_count)
            $valid_logs = $med_scheduled_count;

        $total_taken += $valid_logs;
    }

    $day_status = 'none'; // No meds
    if ($total_scheduled > 0) {
        if ($total_taken >= $total_scheduled) {
            $day_status = 'full'; // ✔️
        } elseif ($total_taken > 0) {
            $day_status = 'partial'; // ⚠️
        } else {
            // Check if date is in past. Future dates -> 'upcoming' (neutral)
            if ($date_str < date('Y-m-d')) {
                $day_status = 'missed'; // ❌
            } else {
                $day_status = 'future';
            }
        }
    }

    $calendar_data[$date_str] = [
        'status' => $day_status,
        'scheduled' => $total_scheduled,
        'taken' => $total_taken
    ];
}


// --- 5. Process Data for Day View (Detailed Breakdown) ---
// We need to group by Family Member
$day_breakdown = []; // [ 'Mother' => [ 'stats' => ..., 'meds' => [...] ] ]

// Helper to get member name/ID
function get_member_key($med_id)
{
    $fid = get_post_meta($med_id, 'family_member_id', true);
    if ($fid)
        return $fid;
    return 'me';
}

// 1. Initialize Groups
$groups = ['me' => 'Me'];
foreach ($family_members as $fm) {
    $groups[$fm->ID] = $fm->post_title;
}
// Filter groups if filter is active
if ($filter_member !== 'all') {
    $available_keys = array_keys($groups);
    if (!in_array($filter_member, $available_keys)) {
        // Should not happen if logic is correct, but safe fallback
    } else {
        // Keep only selected
        $single_name = $groups[$filter_member];
        $groups = [];
        $groups[$filter_member] = $single_name;
    }
}

// 2. Populate structure
foreach ($groups as $key => $name) {
    $day_breakdown[$key] = [
        'name' => $name,
        'scheduled' => 0,
        'taken' => 0,
        'missed' => 0,
        'meds' => []
    ];
}

// 3. Fill with Meds
foreach ($medications as $med) {
    $fid = get_post_meta($med->ID, 'family_member_id', true);
    $group_key = $fid ? $fid : 'me';

    // If we filtered out this group, skip (though get_medications_for_history should have handled it)
    if (!isset($day_breakdown[$group_key]))
        continue;

    $meta = get_post_meta($med->ID);

    // --- Consistency Checks ---
    $s_type = isset($meta['schedule_type'][0]) ? $meta['schedule_type'][0] : 'daily';
    $start_date = isset($meta['start_date'][0]) ? $meta['start_date'][0] : '';
    if (!$start_date) {
        $start_date = date('Y-m-d', strtotime($med->post_date));
    }

    // Check Date Range
    if ($selected_date < $start_date) {
        continue;
    }

    // Check Day Logic
    $sel_w_day = strtolower(date('D', strtotime($selected_date)));
    if ($s_type === 'weekly') {
        $sel_days = isset($meta['selected_weekdays'][0]) ? maybe_unserialize($meta['selected_weekdays'][0]) : [];
        if (!is_array($sel_days) || !in_array($sel_w_day, $sel_days)) {
            continue; // Not scheduled for this day
        }
    } elseif ($s_type === 'as_needed') {
        // As Needed: Only show if there is a log (Taken)
        $has_log = isset($log_map[$selected_date][$med->ID]);
        if (!$has_log) {
            continue;
        }
    }

    $dose_times = isset($meta['dose_times'][0]) ? maybe_unserialize($meta['dose_times'][0]) : [];
    if (!is_array($dose_times))
        $dose_times = [];

    if (empty($dose_times))
        continue;

    // Get logs for this med on selected date
    $med_logs_raw = isset($log_map[$selected_date][$med->ID]) ? $log_map[$selected_date][$med->ID] : [];
    // This is array of WP_Post objects

    // Map Doses
    // We iterate Scheduled Times. For each, we look for a matching Log.
    $used_log_ids = [];

    foreach ($dose_times as $dt_sched) {
        $sched_time_str = $dt_sched['time']; // e.g., "08:00 AM"
        $dosage_str = isset($dt_sched['dosage']) ? $dt_sched['dosage'] : '';

        $status = 'Missed'; // Default
        $actual_time = '';
        $log_note = '';

        // Find a log that hasn't been used. 
        $found_log = null;
        foreach ($med_logs_raw as $lg_obj) {
            if (in_array($lg_obj->ID, $used_log_ids))
                continue;

            // For improved matching, we could check if log time is close to sched time.
            // For MVP, just greedy match is acceptable.
            $found_log = $lg_obj;
            $used_log_ids[] = $lg_obj->ID;
            break;
        }

        if ($found_log) {
            $s = get_post_meta($found_log->ID, 'status', true);
            $t = get_post_meta($found_log->ID, 'log_time', true); // e.g. "09:05 PM"
            $status = ucfirst($s);
            $actual_time = $t;

            // Check Late Status
            if ($status === 'Taken') {
                $sched_ts = strtotime($selected_date . ' ' . $sched_time_str);
                $log_ts = strtotime($selected_date . ' ' . $t);

                // If parsing failed (e.g. empty), ignore. 
                // Using 15 minute grace period (900 seconds)
                if ($sched_ts && $log_ts && $log_ts > ($sched_ts + 900)) {
                    $status = 'Late';
                }
            }
        } else {
            // No log found. 
            // If date is future: Status = "Scheduled"
            if ($selected_date > date('Y-m-d')) {
                $status = 'Scheduled';
            } elseif ($selected_date == date('Y-m-d')) {
                // TODAY Logic: Use 'Scheduled' + Time, do NOT mark Missed yet.
                // Request: "For today ... shown as 'Scheduled'"
                $status = 'Scheduled';
            } else {
                $status = 'Missed';
            }
        }

        // Filter by Status if filter active
        if ($filter_status !== 'all') {
            // status can be 'Taken', 'Skipped', 'Missed', 'Scheduled', 'Upcoming'
            // Map our robust statuses to simplier filter keys
            // filter keys: taken, missed, skipped
            $map_status = strtolower($status);
            if ($map_status === 'upcoming' || $map_status === 'scheduled')
                $map_status = 'missed'; // Treat as waiting? Or show all?
            // Actually usually filters hide the others.

            // Let's match strict string
            if (strtolower($status) !== $filter_status) {
                // But wait, if filter is 'missed', we want 'Missed'.
                // If filter is 'taken', we want 'Taken'.
                // If status is 'Upcoming' and we filter 'All', it shows.
                // If filter is 'Missed', 'Upcoming' probably shouldn't show? Or should?
                // Let's implement strict filtering.
                if (strtolower($status) !== $filter_status)
                    continue;
            }
        }

        // Update Group Stats (Always count stats based on ALL meds, or filtered? Usually stats reflect the breakdown)
        // Let's update stats based on what we SHOW.
        $day_breakdown[$group_key]['scheduled']++;
        if ($status === 'Taken' || $status === 'Skipped') {
            $day_breakdown[$group_key]['taken']++;
        } elseif ($status === 'Missed') {
            $day_breakdown[$group_key]['missed']++;
        }

        // Add to details
        $day_breakdown[$group_key]['meds'][] = [
            'name' => get_the_title($med->ID),
            'sched_time' => $sched_time_str,
            'dosage' => $dosage_str,
            'status' => $status,
            'actual_time' => $actual_time,
            'schedule_type' => $s_type,
            'date' => $selected_date
        ];
    }
}
?>

<div class="pillpalnow-history-page">
    <div class="history-container">
        <!-- Header Row -->
        <header class="history-header">
            <div class="header-top">
                <h1 class="page-title">
                    <?php esc_html_e('History', 'pillpalnow'); ?>
                </h1>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pillpalnow_download_report">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($filter_member); ?>">
                    <button type="submit" class="btn-pill">
                        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Export PDF
                    </button>
                </form>

                <!-- Share History Button -->
                <button id="pillpalnow-share-history-btn" class="btn-pill" style="margin-left: 10px;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    Share History
                </button>
            </div>

            <!-- Controls Row -->
            <div class="controls-row">
                <!-- Month Nav -->
                <div class="month-nav pill-container">
                    <?php
                    $prev_month = date('Y-m', strtotime($month_start . ' -1 month'));
                    $next_month = date('Y-m', strtotime($month_start . ' +1 month'));
                    ?>
                    <a href="?month=<?php echo $prev_month; ?>&member=<?php echo $filter_member; ?>&status=<?php echo $filter_status; ?>"
                        class="nav-arrow" title="Previous Month">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    <span class="month-label"><?php echo date('F Y', $ts_month); ?></span>
                    <a href="?month=<?php echo $next_month; ?>&member=<?php echo $filter_member; ?>&status=<?php echo $filter_status; ?>"
                        class="nav-arrow" title="Next Month">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>

                <!-- Filters -->
                <div class="filters-group">
                    <div class="select-wrapper">
                        <select
                            onchange="location.href='?member='+this.value+'&date=<?php echo $selected_date; ?>&month=<?php echo $selected_month; ?>&status=<?php echo $filter_status; ?>'"
                            class="custom-select">
                            <option value="all" <?php selected($filter_member, 'all'); ?>>All Members</option>
                            <option value="me" <?php selected($filter_member, 'me'); ?>>Me</option>
                            <?php foreach ($family_members as $fm): ?>
                                <option value="<?php echo esc_attr($fm->ID); ?>" <?php selected($filter_member, $fm->ID); ?>>
                                    <?php echo esc_html($fm->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <svg class="select-icon" width="12" height="12" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </div>
                </div>
            </div>
        </header>

        <!-- Calendar Card -->
        <div class="glass-panel calendar-card">
            <!-- Days Header -->
            <div class="calendar-header-row">
                <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d): ?>
                    <div class="day-label"><?php echo $d; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- Days Grid -->
            <div class="calendar-grid">
                <?php
                $first_day_idx = date('w', $ts_month);
                for ($i = 0; $i < $first_day_idx; $i++) {
                    echo '<div></div>';
                }

                $days_in_current_month = date('t', $ts_month);
                for ($d = 1; $d <= $days_in_current_month; $d++) {
                    $loop_date = date('Y-m-', $ts_month) . str_pad($d, 2, '0', STR_PAD_LEFT);
                    $meta = isset($calendar_data[$loop_date]) ? $calendar_data[$loop_date] : ['status' => 'none'];
                    $is_selected = ($loop_date === $selected_date);
                    $is_today = ($loop_date === date('Y-m-d'));

                    // Indicators
                    $indicator_class = '';
                    if ($meta['status'] === 'full')
                        $indicator_class = 'dot-success';
                    elseif ($meta['status'] === 'partial')
                        $indicator_class = 'dot-warning';
                    elseif ($meta['status'] === 'missed')
                        $indicator_class = 'dot-danger';

                    // Styling
                    $cell_classes = ['calendar-cell'];
                    if ($is_selected)
                        $cell_classes[] = 'is-selected';
                    elseif ($is_today)
                        $cell_classes[] = 'is-today';
                    else
                        $cell_classes[] = 'is-default';

                    $cls_str = implode(' ', $cell_classes);
                    $dot_html = $indicator_class ? "<span class='status-dot {$indicator_class}'></span>" : "";

                    echo "<a href='?date={$loop_date}&month={$selected_month}&member={$filter_member}&status={$filter_status}' 
                             class='{$cls_str}'>
                            <span class='day-num'>{$d}</span>
                            {$dot_html}
                          </a>";
                }
                ?>
            </div>
        </div>

        <!-- Activity Feed -->
        <div class="feed-section">
            <div class="feed-header">
                <h3 class="feed-title">
                    <?php if ($selected_date === date('Y-m-d')): ?>
                        <span class="live-dot"></span> Today's Schedule
                    <?php else: ?>
                        <?php echo date('l, M jS', strtotime($selected_date)); ?>
                    <?php endif; ?>
                </h3>
                <?php if ($selected_date !== date('Y-m-d')): ?>
                    <a href="?date=<?php echo date('Y-m-d'); ?>&member=<?php echo $filter_member; ?>&status=<?php echo $filter_status; ?>"
                        class="jump-link">
                        Jump to Today
                    </a>
                <?php endif; ?>
            </div>

            <div class="medication-list">
                <?php
                $has_data = false;
                foreach ($day_breakdown as $group):
                    if (empty($group['meds']))
                        continue;
                    $has_data = true;
                    $completion = $group['scheduled'] > 0 ? round(($group['taken'] / $group['scheduled']) * 100) : 0;

                    // Ring Color Class
                    $ring_class = 'ring-gray';
                    if ($completion == 100)
                        $ring_class = 'ring-success';
                    elseif ($completion > 0)
                        $ring_class = 'ring-warning';
                    ?>
                    <div class="glass-panel member-group">
                        <!-- User Header -->
                        <div class="member-header">
                            <div class="member-info">
                                <div class="avatar-circle">
                                    <?php echo substr($group['name'], 0, 1); ?>
                                </div>
                                <h4 class="member-name"><?php echo esc_html($group['name']); ?></h4>
                            </div>
                            <div class="progress-wrapper">
                                <span
                                    class="progress-text"><?php echo $group['taken']; ?>/<?php echo $group['scheduled']; ?></span>
                                <svg class="progress-ring" viewBox="0 0 36 36">
                                    <path class="ring-bg"
                                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                    <path class="ring-val <?php echo $ring_class; ?>"
                                        stroke-dasharray="<?php echo $completion; ?>, 100"
                                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                </svg>
                            </div>
                        </div>

                        <!-- Meds -->
                        <div class="meds-container">
                            <?php foreach ($group['meds'] as $med):
                                $s = $med['status'];
                                $row_class = 'status-' . strtolower($s);
                                $icon_path = 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'; // default clock
                                if ($s === 'Taken')
                                    $icon_path = 'M5 13l4 4L19 7';
                                elseif ($s === 'Missed')
                                    $icon_path = 'M6 18L18 6M6 6l12 12';
                                elseif ($s === 'Skipped')
                                    $icon_path = 'M13 10V3L4 14h7v7l9-11h-7z';

                                // Determine Display Logic based on Schedule Type
                                $sched_type = isset($med['schedule_type']) ? $med['schedule_type'] : 'daily';
                                $display_status = $med['status'];

                                // Format time explicitly to h:i A (e.g. 08:00 AM)
                                $display_time_formatted = date('h:i A', strtotime($med['sched_time']));
                                $display_time = $display_time_formatted;

                                if ($sched_type === 'daily') {
                                    // Daily: If status is 'Scheduled' or 'Upcoming', show the exact time instead of text.
                                    if ($s === 'Scheduled' || $s === 'Upcoming') {
                                        $display_status = $display_time_formatted;
                                    }
                                } else {
                                    // Weekly / Custom
                                    // "History shows Scheduled On + Date"
                                    if ($s === 'Scheduled' || $s === 'Upcoming' || $s === 'Missed') {
                                        $display_status = 'Scheduled On ' . date('M j', strtotime($med['date']));
                                    }
                                }
                                ?>
                                <div class="med-row <?php echo $row_class; ?>">
                                    <div class="med-info-left">
                                        <div class="med-icon-box">
                                            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="<?php echo $icon_path; ?>" />
                                            </svg>
                                        </div>
                                        <div class="med-details">
                                            <p class="med-name"><?php echo esc_html($med['name']); ?></p>
                                            <div class="med-meta">
                                                <span class="med-dose"><?php echo esc_html($med['dosage']); ?></span>
                                                <span class="separator">•</span>
                                                <span class="med-time"><?php echo esc_html($display_time); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="med-status-right">
                                        <span class="status-badge"><?php echo esc_html($display_status); ?></span>
                                        <?php if ($med['actual_time'] && $med['status'] === 'Taken'): ?>
                                            <span class="taken-at">@<?php echo esc_html($med['actual_time']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$has_data): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            </svg>
                        </div>
                        <p class="empty-text">No schedule for this day.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>



<!-- Share History Modal -->
<div id="pillpalnow-share-modal" class="modal-overlay hidden"
    style="display:none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center;">
    <div class="modal-content glass-panel"
        style="background: #1f2937; positoin: relative; padding: 2rem; border-radius: 12px; width: 90%; max-width: 500px; color: #fff;">
        <h3 class="text-xl font-bold mb-4">Share History via Email</h3>
        <p class="text-gray-400 mb-4">Send a dose history report for
            <strong><?php echo date('F Y', $ts_month); ?></strong>.</p>

        <div class="form-group mb-4">
            <label class="block text-sm mb-2" style="display:block; margin-bottom: 0.5rem;">Email Address(es)</label>
            <input type="text" id="pillpalnow-share-emails"
                class="w-full p-2 rounded bg-gray-800 border border-gray-600 focus:border-blue-500"
                placeholder="user@example.com, family@example.com"
                style="width: 100%; box-sizing: border-box; color: #fff; padding: 0.5rem;">
            <p class="text-xs text-gray-500 mt-1" style="font-size: 0.75rem; color: #9ca3af;">Separate multiple emails
                with commas.</p>
        </div>

        <input type="hidden" id="pillpalnow_share_member_id" value="<?php echo esc_attr($filter_member); ?>">
        <input type="hidden" id="pillpalnow_share_month" value="<?php echo esc_attr($selected_month); ?>">

        <div id="pillpalnow-share-message" class="mb-4 text-sm font-semibold"></div>

        <div class="flex justify-end gap-2"
            style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem;">
            <button class="btn-cancel px-4 py-2 rounded border border-gray-600 hover:bg-gray-700"
                style="padding: 0.5rem 1rem; border-radius: 0.25rem; background: transparent; color: #fff; border: 1px solid #4b5563; cursor: pointer;">Cancel</button>
            <button id="pillpalnow-share-submit"
                class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white font-bold"
                style="padding: 0.5rem 1rem; border-radius: 0.25rem; background: #2563eb; color: #fff; border: none; cursor: pointer;">Send
                Report</button>
        </div>
    </div>
</div>

<style>
    /* --- CSS VARIABLES --- */
    :root {
        --bg-dark: #0B0F19;
        --card-bg: rgba(19, 24, 37, 0.7);
        --border-color: rgba(255, 255, 255, 0.1);
        --primary: #3b82f6;
        --text-main: #ffffff;
        --text-muted: #9ca3af;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
    }

    /* --- LAYOUT --- */
    body {
        margin: 0;
        padding: 0;
        background-color: var(--bg-dark);
    }

    .pillpalnow-history-page {
        background-color: var(--bg-dark);
        min-height: 100vh;
        color: var(--text-main);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        padding-top: 1.5rem;
        padding-bottom: 5rem;
    }

    /* Reset links */
    .pillpalnow-history-page a {
        text-decoration: none;
        color: inherit;
    }

    .history-container {
        max-width: 600px;
        margin: 0 auto;
        padding: 0 1rem;
    }

    /* --- HEADER --- */
    .history-header {
        margin-bottom: 2rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .page-title {
        font-size: 1.75rem;
        font-weight: 700;
        margin: 0;
        color: #fff !important;
        background: linear-gradient(to right, #fff, #9ca3af);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .btn-pill {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--border-color);
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        color: var(--text-muted) !important;
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.2s;
    }

    .btn-pill:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff !important;
    }

    .controls-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
    }

    /* --- MONTH NAV --- */
    .pill-container {
        display: flex;
        align-items: center;
        background: rgba(31, 41, 55, 0.5);
        border: 1px solid var(--border-color);
        border-radius: 9999px;
        padding: 0.25rem;
    }

    .nav-arrow {
        color: var(--text-muted);
        padding: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: background 0.2s;
        width: 32px;
        height: 32px;
    }

    .nav-arrow:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }

    .month-label {
        font-size: 0.875rem;
        font-weight: 600;
        padding: 0 0.75rem;
        min-width: 100px;
        text-align: center;
        color: #fff;
    }

    /* --- FILTERS --- */
    .select-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .custom-select {
        appearance: none;
        background: rgba(31, 41, 55, 0.5);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        font-size: 0.75rem;
        font-weight: 500;
        padding: 0.5rem 2rem 0.5rem 1rem;
        border-radius: 9999px;
        cursor: pointer;
    }

    .custom-select:focus {
        outline: none;
        border-color: var(--primary);
    }

    .select-icon {
        position: absolute;
        right: 0.75rem;
        pointer-events: none;
        color: var(--text-muted);
    }

    /* --- CALENDAR --- */
    .glass-panel {
        background: #131825;
        /* Fallback */
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 1.5rem;
        backdrop-filter: blur(8px);
        margin-bottom: 2rem;
    }

    .calendar-card {
        padding: 1.5rem;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
    }

    .calendar-header-row {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        margin-bottom: 1rem;
        text-align: center;
    }

    .day-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
    }

    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 0.5rem;
    }

    .calendar-cell {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 2.5rem;
        border-radius: 0.75rem;
        text-decoration: none !important;
        transition: all 0.2s;
    }

    .calendar-cell:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    .day-num {
        font-size: 0.875rem;
        font-weight: 500;
    }

    /* States */
    .is-default {
        color: var(--text-muted) !important;
    }

    .is-selected {
        background: var(--primary) !important;
        color: #fff !important;
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        z-index: 2;
    }

    .is-today {
        background: rgba(255, 255, 255, 0.1);
        color: #fff !important;
        border: 1px solid var(--border-color);
    }

    /* Dots */
    .status-dot {
        position: absolute;
        bottom: 4px;
        width: 4px;
        height: 4px;
        border-radius: 50%;
    }

    .dot-success {
        background: var(--success);
        box-shadow: 0 0 6px var(--success);
    }

    .dot-warning {
        background: var(--warning);
        box-shadow: 0 0 6px var(--warning);
    }

    .dot-danger {
        background: var(--danger);
        box-shadow: 0 0 6px var(--danger);
    }

    /* --- FEED --- */
    .feed-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .feed-title {
        font-size: 1.125rem;
        font-weight: 700;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0;
    }

    .live-dot {
        width: 0.5rem;
        height: 0.5rem;
        background: var(--success);
        border-radius: 50%;
        display: inline-block;
    }

    .jump-link {
        color: var(--primary);
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        text-decoration: none;
    }

    /* Member Group */
    .member-group {
        padding: 1.25rem;
        margin-bottom: 1rem;
    }

    .member-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .member-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .avatar-circle {
        width: 2rem;
        height: 2rem;
        border-radius: 50%;
        background: #1f2937;
        color: #d1d5db;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        border: 1px solid var(--border-color);
    }

    .member-name {
        margin: 0;
        font-weight: 700;
        color: #fff;
        font-size: 1rem;
    }

    .progress-wrapper {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .progress-text {
        font-size: 0.75rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    .progress-ring {
        width: 1.25rem;
        height: 1.25rem;
        transform: rotate(-90deg);
    }

    .ring-bg {
        stroke: #374151;
        stroke-width: 3;
        fill: none;
    }

    .ring-val {
        stroke-width: 3;
        fill: none;
        stroke-linecap: round;
    }

    .ring-success {
        stroke: var(--success);
    }

    .ring-warning {
        stroke: var(--warning);
    }

    .ring-gray {
        stroke: #4b5563;
    }

    /* Med Rows */
    .med-row {
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        border-radius: 0.75rem;
        border: 1px solid transparent;
        margin-bottom: 0.5rem;
        transition: background 0.2s;
    }

    .med-row:hover {
        background: rgba(255, 255, 255, 0.03);
    }

    .status-taken {
        background: rgba(16, 185, 129, 0.1);
        border-color: rgba(16, 185, 129, 0.2);
    }

    .status-taken .med-name {
        color: #fff;
    }

    .status-taken .status-badge {
        color: var(--success);
    }

    .status-taken .med-icon-box {
        background: transparent;
        color: var(--success);
        border-color: transparent;
    }

    .status-late {
        background: rgba(245, 158, 11, 0.1);
        border-color: rgba(245, 158, 11, 0.2);
    }

    .status-late .status-badge {
        color: var(--warning);
    }

    .status-late .med-icon-box {
        color: var(--warning);
    }

    .status-missed {
        background: rgba(239, 68, 68, 0.1);
        border-color: rgba(239, 68, 68, 0.2);
    }

    .status-missed .status-badge {
        color: var(--danger);
    }

    .status-missed .med-icon-box {
        color: var(--danger);
    }

    .status-upcoming {
        background: rgba(255, 255, 255, 0.02);
        border-color: var(--border-color);
    }

    .med-info-left {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .med-icon-box {
        padding: 0.5rem;
        border-radius: 0.5rem;
        background: #0B0F19;
        border: 1px solid var(--border-color);
        color: var(--text-muted);
        display: flex;
    }

    .med-details {
        line-height: 1.2;
    }

    .med-name {
        margin: 0;
        font-weight: 700;
        font-size: 0.9375rem;
        color: #fff;
    }

    .med-meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.125rem;
    }

    .separator {
        opacity: 0.5;
    }

    .med-status-right {
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    .status-badge {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .taken-at {
        font-size: 0.65rem;
        font-family: monospace;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 3rem 0;
        border: 2px dashed var(--border-color);
        border-radius: 1.5rem;
        background: rgba(255, 255, 255, 0.02);
    }

    .empty-icon {
        color: #4b5563;
        margin-bottom: 1rem;
    }

    .empty-text {
        color: #9ca3af;
        margin: 0;
    }
</style>

<?php
get_footer();
