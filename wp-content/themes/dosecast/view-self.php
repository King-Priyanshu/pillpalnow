<?php
/**
 * Template Part: Self View (User's own dashboard)
 * Included by page-dashboard.php when ?view=self
 */

if (!defined('ABSPATH'))
    exit;

$current_user = wp_get_current_user();
$current_user_id = $current_user->ID;

// 1. Get Medications for current user (Self) using WP_Query for Pagination
$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
$med_args = array(
    'post_type' => 'medication',
    'posts_per_page' => 10,
    'paged' => $paged,
    'post_status' => 'publish',
    'author' => $current_user_id, // Only medications authored by current user
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'relation' => 'OR',
            array('key' => 'family_member_id', 'value' => '0', 'compare' => '='),
            array('key' => 'family_member_id', 'value' => '', 'compare' => '='),
            array('key' => 'family_member_id', 'compare' => 'NOT EXISTS')
        ),
        array(
            'relation' => 'OR',
            array('key' => 'assigned_user_id', 'value' => $current_user_id),
            array('key' => 'assigned_to', 'value' => 'Self'),
            array('key' => 'assigned_user_id', 'compare' => 'NOT EXISTS')
        )
    )
);
$med_query = new WP_Query($med_args);
$medications = $med_query->posts;

// 1.1 Get Active Reminders (Due Now)
$active_reminders = get_posts(array(
    'post_type' => 'reminder_log',
    'author' => $current_user_id,
    'posts_per_page' => -1,
    'meta_query' => array(
        'relation' => 'AND',
        array('key' => 'status', 'value' => 'pending'),
        array('key' => 'scheduled_datetime', 'value' => current_time('timestamp'), 'compare' => '<=')
    )
));




// 2. Calculate Today's Summary
date_default_timezone_set('UTC');
$today_date = date('Y-m-d', current_time('timestamp'));

// Use centralized stats for consistency
if (class_exists('PillPalNow_Data_Validator')) {
    $daily_stats = PillPalNow_Data_Validator::get_daily_stats($current_user_id, $today_date);
} else {
    $daily_stats = array('taken_count' => 0, 'total_scheduled' => 0, 'percentage' => 0);
}

$count_taken = $daily_stats['taken_count'];
$denominator = $daily_stats['total_scheduled'];
$percent = $daily_stats['percentage'];

get_header();
?>

<div class="app-container flex-col justify-between" style="min-height: 100vh;">
    <div class="container flex-1">

        <!-- Header -->
        <header class="app-header border-b border-gray-800">
            <a href="<?php echo home_url('/dashboard'); ?>" class="flex items-center text-secondary">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5"></path>
                    <path d="M12 19l-7-7 7-7"></path>
                </svg>
            </a>
            <div class="flex-1 text-center">
                <h1 class="text-lg font-bold">My Medications</h1>
                <p class="text-xs text-secondary">
                    Self
                    <?php
                    $email = $current_user->user_email;
                    if ($email)
                        echo ' • ' . esc_html($email);
                    ?>
                </p>
            </div>
            <div
                class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-600 to-purple-800 flex items-center justify-center text-sm font-bold text-white">
                <?php echo strtoupper(substr($current_user->display_name, 0, 1)); ?>
            </div>
        </header>

        </header>

        <!-- Error Handling -->
        <?php if (isset($_GET['error']) && $_GET['error'] === 'already_taken'): ?>
            <div class="error-container p-4"
                style="background: rgba(220, 38, 38, 0.1); border-bottom: 1px solid rgba(220, 38, 38, 0.2); position: sticky; top: 0; z-index: 1000;">
                <div class="flex items-center gap-3 max-w-lg mx-auto">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        class="text-red-400">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <p class="text-red-400 font-semibold">One action per dose allowed. This dose has already been logged.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <main class="flex flex-col gap-6 pb-24 p-4">

            <!-- 0. Reminder Notifications -->
            <?php
            // Query for Pending OR (Postponed AND Due)
            // Since complex meta queries with OR logic on different keys can be tricky, 
            // we'll fetch 'pending' and 'postponed' and filter in PHP for robustness 
            // or use a smart meta query.
            // Let's use a robust meta query.
            
            $active_reminders_query = new WP_Query(array(
                'post_type' => 'reminder_log',
                'author' => $current_user_id,
                'posts_per_page' => -1,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'relation' => 'OR',
                        array('key' => 'status', 'value' => 'pending'),
                        array('key' => 'status', 'value' => 'postponed')
                    )
                )
            ));

            $active_reminders = array();
            $current_ts = current_time('timestamp');
            $today_date_check = date('Y-m-d', $current_ts);

            foreach ($active_reminders_query->posts as $rem) {
                $st = get_post_meta($rem->ID, 'status', true);
                $med_id = get_post_meta($rem->ID, 'medication_id', true);
                $sched_ts = get_post_meta($rem->ID, 'scheduled_datetime', true);
                $sched_date = date('Y-m-d', $sched_ts);

                // CHECK IF DOSE ALREADY LOGGED for this medication + date
                $existing_log = get_posts(array(
                    'post_type' => 'dose_log',
                    'posts_per_page' => 1,
                    'meta_query' => array(
                        'relation' => 'AND',
                        array('key' => 'medication_id', 'value' => $med_id),
                        array('key' => 'log_date', 'value' => $sched_date),
                        array(
                            'relation' => 'OR',
                            array('key' => 'status', 'value' => 'taken'),
                            array('key' => 'status', 'value' => 'skipped')
                        )
                    )
                ));

                // If a dose log exists for this med/date with taken/skipped, skip this reminder
                if (!empty($existing_log)) {
                    $log_time = get_post_meta($existing_log[0]->ID, 'log_time', true);
                    if ($log_time) {
                        $log_ts = strtotime($sched_date . ' ' . $log_time);
                        $time_diff = abs($sched_ts - $log_ts);
                        if ($time_diff < 7200) { // 2 hours tolerance
                            continue; // Skip this reminder, dose already logged
                        }
                    } else {
                        continue; // Log exists but no time - assume it covers this slot
                    }
                }

                if ($st === 'pending') {
                    if ($sched_ts <= $current_ts) {
                        $active_reminders[] = $rem;
                    }
                } elseif ($st === 'postponed') {
                    $until = get_post_meta($rem->ID, 'postponed_until', true);
                    if ($until && $until <= $current_ts) {
                        $active_reminders[] = $rem;
                    }
                }
            }

            if (!empty($active_reminders)): ?>
                <section class="mb-4">
                    <h2 class="text-sm font-semibold text-danger uppercase tracking-wider mb-2 animate-pulse">
                        Due Now
                    </h2>
                    <div class="flex flex-col gap-3">
                        <?php foreach ($active_reminders as $rem):
                            $med_id = get_post_meta($rem->ID, 'medication_id', true);
                            $sched_ts = get_post_meta($rem->ID, 'scheduled_datetime', true);
                            // If postponed, maybe show original time or postponed time?
                            // Let's show the time it became due (scheduled). 
                            $med_title = get_the_title($med_id);
                            $dosage = get_post_meta($med_id, 'dosage', true);
                            ?>
                            <div class="card p-4 border-l-4 border-l-red-500 bg-red-500/5 animate-fade-in"
                                id="reminder-<?php echo $rem->ID; ?>">
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <h3 class="font-bold text-lg text-red-400">
                                            <?php echo esc_html($med_title); ?>
                                        </h3>
                                        <p class="text-sm text-secondary">
                                            <?php echo esc_html($dosage); ?> •
                                            <span class="font-mono"><?php echo date('h:i A', $sched_ts); ?></span>
                                        </p>
                                    </div>
                                    <div class="text-danger">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2">
                                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                        </svg>
                                    </div>
                                </div>

                                <div class="flex gap-2">
                                    <button onclick="handleReminderAction(<?php echo $rem->ID; ?>, 'taken')"
                                        class="flex-1 btn btn-primary py-2 text-xs rounded shadow-lg transform hover:scale-105 transition-transform font-bold">
                                        TAKEN
                                    </button>
                                    <button onclick="handleReminderAction(<?php echo $rem->ID; ?>, 'skip')"
                                        class="flex-1 btn bg-gray-800 text-gray-300 py-2 text-xs rounded hover:bg-gray-700 border border-gray-700">
                                        SKIP
                                    </button>
                                    <button onclick="openPostponeModal(<?php echo $rem->ID; ?>)"
                                        class="flex-1 btn bg-gray-800 text-gray-300 py-2 text-xs rounded hover:bg-gray-700 border border-gray-700">
                                        POSTPONE
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- 1. Today's Summary -->
            <section>
                <div class="card p-6 flex flex-col items-center justify-center">
                    <h2 class="text-sm font-semibold text-secondary uppercase tracking-wider mb-2">
                        <?php echo date('l, M j'); ?>
                    </h2>
                    <div class="text-4xl font-bold mb-1">
                        <?php
                        // Identify Skipped Counts (to remove from Denominator)
                        // Note: $taken_doses_log has count of ALL logs for that med/date.
                        // We need to distinguish Taken vs Skipped in the previous loop or re-query.
                        // Let's re-query logs generally for today to get a precise breakdown.
                        $text_color = ($count_taken > 0) ? 'text-success' : 'text-secondary';
                        ?>
                        <span class="<?php echo $text_color; ?>">
                            <?php echo $count_taken; ?>
                        </span>
                        <span class="text-secondary text-2xl">/</span>
                        <span class="text-secondary text-2xl">
                            <?php echo $denominator; ?>
                        </span>
                    </div>

                    <p class="text-sm text-secondary">
                        Daily Status: <span class="text-white font-bold"><?php echo $percent; ?>%</span>
                    </p>
                </div>
            </section>

            <!-- 2. Medication List -->
            <section>
                <div class="flex justify-between items-center mb-3">
                    <h2 class="text-sm font-semibold text-secondary uppercase tracking-wider">
                        Medications
                    </h2>
                    <a href="<?php echo home_url('/add-medication'); ?>" class="text-xs text-primary font-bold">+ Add
                        New</a>
                </div>

                <?php if (empty($medications)): ?>
                    <p class="text-secondary text-sm italic">No medications assigned.</p>
                <?php else: ?>
                    <div class="flex flex-col gap-3">
                        <?php foreach ($medications as $med):
                            $med_id = $med->ID;
                            $stock = get_post_meta($med_id, 'stock_quantity', true);
                            $s_type = get_post_meta($med_id, 'schedule_type', true);

                            // --- Frequency Filtering ---
                            if ($s_type === 'weekly') {
                                $days = get_post_meta($med_id, 'pillpalnow_days', true);
                                if (!is_array($days))
                                    $days = array();
                                if (!in_array(strtolower(date('l', current_time('timestamp'))), array_map('strtolower', $days))) {
                                    continue;
                                }
                            }
                            // 'daily' and 'as_needed' are always included for 'Today'
                            // --- End Filtering ---
                    
                            $dose_times_arr = get_post_meta($med_id, 'dose_times', true);
                            if (!is_array($dose_times_arr) || empty($dose_times_arr)) {
                                // Fallback: Display as single generic entry
                                $dose_times_arr = array(array('time' => '', 'dosage' => get_post_meta($med_id, 'dosage', true)));
                            }

                            // Retrieve ALL logs for this medication for today to map correctly
                            $med_logs_today = get_posts(array(
                                'post_type' => 'dose_log',
                                'posts_per_page' => -1,
                                'meta_query' => array(
                                    'relation' => 'AND',
                                    array('key' => 'medication_id', 'value' => $med->ID),
                                    array('key' => 'log_date', 'value' => $today_date)
                                ),
                                'author' => $current_user_id,
                                'orderby' => 'ID',
                                'order' => 'ASC' // Assume chronological order of action
                            ));

                            foreach ($dose_times_arr as $index => $dt):
                                $time_str = isset($dt['time']) ? $dt['time'] : '';
                                $dosage_val = isset($dt['dosage']) ? $dt['dosage'] : '';
                                if (!$dosage_val)
                                    $dosage_val = '1';

                                // Determine Status for this Specific Slot
                                // NEW Logic: Search logs for one matching this dose_index
                                $slot_status = 'pending';
                                $log_for_slot = null;
                                $log_status = null;
                                foreach ($med_logs_today as $l) {
                                    $l_idx = get_post_meta($l->ID, 'dose_index', true);
                                    if ($l_idx !== '' && (int) $l_idx === $index) {
                                        $log_for_slot = $l;
                                        break;
                                    }
                                }

                                if ($log_for_slot) {
                                    $log_status = get_post_meta($log_for_slot->ID, 'status', true);
                                    // Map log status to slot status
                                    if ($log_status === 'taken')
                                        $slot_status = 'taken';
                                    elseif ($log_status === 'skipped')
                                        $slot_status = 'skipped';
                                    elseif ($log_status === 'postponed')
                                        $slot_status = 'postponed';
                                    else
                                        $slot_status = 'taken'; // Fallback
                                }

                                // As Needed logic remains simple: just show available button always unless stock empty
                                if ($s_type === 'as_needed') {
                                    $slot_status = 'as_needed';
                                }

                                // HIDE COMPLETED RULE
                                // If status is Taken, Skipped, or Superseded, remove from dashboard list.
                                if ($slot_status === 'taken' || $slot_status === 'skipped' || $log_status === 'superseded') {
                                    continue;
                                }
                                ?>
                                <div
                                    class="card p-4 <?php echo ($s_type === 'as_needed') ? 'border-l-4 border-l-blue-400' : ''; ?>">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <h3 class="font-bold text-lg"><?php echo esc_html($med->post_title); ?></h3>
                                            <p class="text-sm text-secondary">
                                                <?php echo esc_html($dosage_val); ?> pill(s)
                                                <?php if ($time_str): ?>
                                                    • <span
                                                        class="font-mono text-white"><?php echo date('h:i A', strtotime($time_str)); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <a href="<?php echo home_url('/edit-medication?med_id=' . $med->ID); ?>"
                                            class="text-xs text-primary bg-blue-500/10 px-2 py-1 rounded">Edit</a>
                                    </div>

                                    <div class="flex justify-between items-end mt-2">

                                        <!-- STATUS LABEL -->
                                        <div class="text-xs">
                                            <?php if ($slot_status === 'taken'): ?>
                                                <span class="text-success font-bold">Taken</span>
                                            <?php elseif ($slot_status === 'skipped'): ?>
                                                <span class="text-warning font-bold">Skipped</span>
                                            <?php elseif ($slot_status === 'postponed'): ?>
                                                <span class="text-orange-400 font-bold">Postponed</span>
                                            <?php else: // Pending/Due/AsNeeded ?>
                                                <?php if ($s_type !== 'as_needed'): ?>
                                                    <span class="text-secondary italic">Pending</span>
                                                <?php else: ?>
                                                    <?php echo ($stock < 5 ? '<span class="text-danger font-bold">Low Stock (' . $stock . ')</span>' : '<span class="text-secondary">Available</span>'); ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <!-- ACTION BUTTONS -->
                                        <?php
                                        // Show Button if status is PENDING or POSTPONED or AS_NEEDED
                                        // If Taken or Skipped, show nothing (or undo?)
                            
                                        if ($slot_status === 'taken' || $slot_status === 'skipped') {
                                            // Completed.
                                        } else {
                                            // Check Timing for Pending (Daily/Weekly)
                                            // If it is the "Next Active" one or "Postponed", allow interaction.
                                            // To keep it simple and robust: Allow interaction if time is reached OR if it's the very first pending slot.
                            
                                            // Only show countdown if it's strictly in the future AND no previous pending slots exist?
                                            // User requested "Separate Schedules". So each card acts independently?
                                            // If I have 8am and 8pm. It's 10am. 8am should be "Missed/Due", 8pm "Future".
                            
                                            $can_take = true;
                                            $countdown_val = 0;

                                            if ($s_type === 'daily' || $s_type === 'weekly') {
                                                if ($time_str) {
                                                    $sched_ts = strtotime($today_date . ' ' . $time_str);
                                                    // Window: allow taking up to 2 hours early
                                                    if ($sched_ts > current_time('timestamp') + (2 * 3600)) {
                                                        $can_take = false;
                                                        $countdown_val = $sched_ts;
                                                    }
                                                }
                                            }

                                            // Force enable if Postponed (it allows re-taking)
                                            if ($slot_status === 'postponed')
                                                $can_take = true;

                                            if ($can_take || $s_type === 'as_needed') {
                                                $logger_url = add_query_arg(array(
                                                    'med_id' => $med->ID,
                                                    'dose_index' => $index,
                                                    'scheduled_time' => $time_str
                                                ), home_url('/dose-logger'));
                                                echo '<a href="' . esc_url($logger_url) . '" 
                                                          class="btn btn-primary py-1 px-3 text-xs w-auto rounded-full">Take Now</a>';
                                            } else {
                                                // Countdown - calculate time remaining
                                                $diff = $sched_ts - current_time('timestamp');
                                                $hours = floor($diff / 3600);
                                                $minutes = floor(($diff % 3600) / 60);
                                                $countdown_display = sprintf('%dh %02dm', $hours, $minutes);
                                                echo '<span class="card-timer-text text-secondary text-xs font-medium bg-white/5 px-2 py-1 rounded"
                                                              data-target-ts="' . ($sched_ts * 1000) . '">' . esc_html($countdown_display) . '</span>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; // End Loop specific dose_time ?>
                            <?php
                            // End Loop original logic
                            continue; // Skip the rest of the old loop body
                            ?>
                            <?php
                            // Clean up old closing tags since we output everything above
                            ?>
                            <div></div><!-- dummy close -->
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Pagination Controls -->
                <?php if ($med_query->max_num_pages > 1): ?>
                    <div class="flex justify-center mt-6 gap-2">
                        <?php
                        $big = 999999999;
                        echo paginate_links(array(
                            'base' => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
                            'format' => '?paged=%#%',
                            'current' => max(1, get_query_var('paged')),
                            'total' => $med_query->max_num_pages,
                            'prev_text' => '&laquo; Prev',
                            'next_text' => 'Next &raquo;',
                            'type' => 'list',
                            'mid_size' => 1,
                        ));
                        ?>
                    </div>
                    <style>
                        ul.page-numbers {
                            display: flex;
                            gap: 8px;
                            list-style: none;
                            padding: 0;
                            margin-top: 10px;
                        }

                        ul.page-numbers li a,
                        ul.page-numbers li span {
                            display: block;
                            padding: 8px 12px;
                            background: #1f2937;
                            color: white;
                            border-radius: 4px;
                            border: 1px solid #374151;
                            font-size: 14px;
                        }

                        ul.page-numbers li span.current {
                            background: var(--primary-color);
                            border-color: var(--primary-color);
                            font-weight: bold;
                        }

                        ul.page-numbers li a:hover {
                            background: #374151;
                        }
                    </style>
                <?php endif; ?>
            </section>

            <!-- 3. Dose History (Last 5 Logs) -->
            <section>
                <h2 class="text-sm font-semibold text-secondary uppercase tracking-wider mb-3">
                    Recent History
                </h2>
                <?php
                // Get logs for ANY of these meds
                if (!empty($medications)):
                    $med_ids = array_column($medications, 'ID');
                    $history_logs = get_posts(array(
                        'post_type' => 'dose_log',
                        'posts_per_page' => 5,
                        'meta_query' => array(
                            array(
                                'key' => 'medication_id',
                                'value' => $med_ids,
                                'compare' => 'IN'
                            )
                        )
                    ));
                else:
                    $history_logs = array();
                endif;
                ?>

                <?php if (empty($history_logs)): ?>
                    <p class="text-secondary text-sm italic">No history yet.</p>
                <?php else: ?>
                    <div class="card p-0 overflow-hidden">
                        <?php foreach ($history_logs as $log):
                            $mid = get_post_meta($log->ID, 'medication_id', true);
                            $m_time = get_post_meta($log->ID, 'log_time', true);
                            $m_date = get_post_meta($log->ID, 'log_date', true);
                            $status = get_post_meta($log->ID, 'status', true);

                            $s_type = get_post_meta($mid, 'schedule_type', true);
                            if (!$s_type)
                                $s_type = 'daily';

                            $history_text = '';
                            if ($s_type === 'daily') {
                                // Daily: Show TIME only
                                $history_text = $m_time;
                            } else {
                                // Weekly / Custom: Scheduled On + Date
                                $display_date = date('M j', strtotime($m_date));
                                $history_text = 'Scheduled On ' . $display_date;
                            }

                            // Badge Color
                            $badge_class = 'text-success bg-green-500/10';
                            $status_label = 'Taken';
                            if ($status === 'skipped') {
                                $badge_class = 'text-warning bg-yellow-500/10';
                                $status_label = 'Skipped';
                            } elseif ($status === 'missed') {
                                $badge_class = 'text-danger bg-red-500/10';
                                $status_label = 'Missed';
                            }
                            ?>
                            <div class="p-3 border-b border-gray-800 last:border-0 flex justify-between items-center">
                                <div>
                                    <p class="font-bold text-sm">
                                        <?php echo get_the_title($mid); ?>
                                    </p>
                                    <p class="text-xs text-secondary">
                                        <?php echo esc_html($history_text); ?>
                                    </p>
                                </div>
                                <span class="text-xs font-bold <?php echo $badge_class; ?> px-2 py-1 rounded">
                                    <?php echo $status_label; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </section>

        </main>
    </div>
</div>

<!-- POSTPONE MODAL -->
<div id="postpone-modal"
    class="fixed inset-0 bg-black/80 z-50 hidden flex items-center justify-center backdrop-blur-sm p-4">
    <div class="card w-full max-w-sm p-6 bg-gray-900 border border-gray-800 shadow-2xl relative animate-fade-in">
        <h3 class="text-lg font-bold mb-4">Postpone Until...</h3>

        <input type="hidden" id="postpone-rem-id" value="">

        <div class="flex flex-col gap-3 mb-6">
            <button onclick="submitPostpone(15)"
                class="btn bg-gray-800 border border-gray-700 hover:bg-gray-700 text-left px-4 py-3 rounded">
                15 Minutes
            </button>
            <button onclick="submitPostpone(60)"
                class="btn bg-gray-800 border border-gray-700 hover:bg-gray-700 text-left px-4 py-3 rounded">
                1 Hour
            </button>

            <div class="pt-2 border-t border-gray-800">
                <label class="text-xs text-secondary mb-1 block">Specific Time</label>
                <input type="datetime-local" id="postpone-picker"
                    class="w-full bg-black border border-gray-700 rounded p-2 text-white">
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <button onclick="closePostponeModal()" class="btn text-gray-400 hover:text-white">Cancel</button>
            <button onclick="submitPostponeCustom()"
                class="btn btn-primary px-4 bg-blue-600 hover:bg-blue-500">Confirm</button>
        </div>
    </div>
</div>

<script>
    async function handleReminderAction(notifId, action, extraData = {}) {
        const card = document.getElementById('reminder-' + notifId);
        if (card) {
            card.style.opacity = '0.5';
            card.style.pointerEvents = 'none';
        }

        try {
            const body = {
                notification_id: notifId,
                action: action,
                ...extraData
            };

            const response = await fetch('/wp-json/pillpalnow/v1/reminder-action', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
                },
                body: JSON.stringify(body)
            });

            const data = await response.json();

            if (response.ok) {
                if (card) card.remove();

                // Reload to update progress/history
                window.location.reload();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
                if (card) {
                    card.style.opacity = '1';
                    card.style.pointerEvents = 'auto';
                }
            }
        } catch (e) {
            console.error(e);
            alert('Network error');
            if (card) {
                card.style.opacity = '1';
                card.style.pointerEvents = 'auto';
            }
        }
    }

    // Modal Logic
    const modal = document.getElementById('postpone-modal');
    const postponeIdInput = document.getElementById('postpone-rem-id');
    const picker = document.getElementById('postpone-picker');

    function openPostponeModal(id) {
        postponeIdInput.value = id;
        modal.classList.remove('hidden');

        // Default picker to now + 1h
        const now = new Date();
        now.setHours(now.getHours() + 1);
        now.setMinutes(0);
        // Format for datetime-local: YYYY-MM-DDTHH:mm
        const str = now.toISOString().slice(0, 16);
        picker.value = str;
    }

    function closePostponeModal() {
        modal.classList.add('hidden');
    }

    function submitPostpone(minutes) {
        const id = postponeIdInput.value;
        if (!id) return;

        // Calculate timestamp in seconds
        const nowSec = Math.floor(Date.now() / 1000);
        const target = nowSec + (minutes * 60);

        handleReminderAction(id, 'postpone', { postpone_until: target });
        closePostponeModal();
    }

    function submitPostponeCustom() {
        const id = postponeIdInput.value;
        const val = picker.value;
        if (!id || !val) return;

        const date = new Date(val);
        const target = Math.floor(date.getTime() / 1000);

        handleReminderAction(id, 'postpone', { postpone_until: target });
        closePostponeModal();
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Countdown timer logic moved to dose-logger.js
    });
</script>

<?php get_footer(); ?>