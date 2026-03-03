<?php
/**
 * Template Name: Family Member Detail
 * Template Post Type: family_member
 * 
 * @package PillPalNow
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user_id = get_current_user_id();
$member_id = get_the_ID();
$member = get_post($member_id);

// Security: specific family member must belong to user
if ($member->post_author != $current_user_id) {
    wp_redirect(home_url('/dashboard'));
    exit;
}

get_header();

// 1. Get Medications for this member
$medications = get_posts(array(
    'post_type' => 'medication',
    'posts_per_page' => -1,
    'meta_query' => array(
        array(
            'key' => 'family_member_id',
            'value' => $member_id
        )
    )
));

// 2. Calculate Today's Summary
date_default_timezone_set(get_option('timezone_string') ?: 'UTC');
$today_date = date('Y-m-d', current_time('timestamp'));

$total_doses_today = 0; // Simplified: 1 per med per day logic for MVP unless we parse schedules
$taken_doses_log = array();
$scheduled_doses = array();

// Simplified Logic: 
// - Iterate meds. 
// - If daily, it counts. 
// - Check logs for today.
foreach ($medications as $med) {
    // Check if med was created today and if scheduled time is passed creation time
    $med_created_ts = strtotime($med->post_date);

    $schedule_type = get_post_meta($med->ID, 'schedule_type', true);
    // Assuming mostly 'daily' for MVP demo
    if ($schedule_type === 'daily' || $schedule_type === 'as_needed') {
        // Find logs for today
        $logs = get_posts(array(
            'post_type' => 'dose_log',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => 'medication_id',
                    'value' => $med->ID
                ),
                array(
                    'key' => 'log_date',
                    'value' => $today_date
                )
            )
        ));

        // Logs count (Taken/Skipped) - APPLY SAME RETROACTIVE FILTER
        $count = 0;
        foreach ($logs as $l) {
            $st = get_post_meta($l->ID, 'status', true);
            if ($st === 'taken' || $st === 'skipped') {
                // Check if this log corresponds to a retroactive time
                $log_time = get_post_meta($l->ID, 'log_time', true); // HH:MM:SS
                // If log doesn't have a time (old data?), assume valid or check creation? 
                // Let's rely on log_time matching the slot time.
                if ($log_time) {
                    $log_ts = strtotime($today_date . ' ' . $log_time);
                    if ($log_ts < $med_created_ts) {
                        continue; // Skip counting this log as it belongs to a hidden slot
                    }
                }
                $count++;
            }
        }

        if ($schedule_type === 'daily') {
            $dose_times = get_post_meta($med->ID, 'dose_times', true);

            // Calculate valid scheduled doses for today
            $valid_daily_count = 0;
            if (is_array($dose_times)) {
                foreach ($dose_times as $dt) {
                    // Check if this specific dose time is valid vs creation time
                    $dt_ts_today = strtotime($today_date . ' ' . $dt['time']);

                    // If created today, only count doses AFTER creation time
                    if ($dt_ts_today < $med_created_ts) {
                        continue;
                    }
                    $valid_daily_count++;
                }
            } else {
                $valid_daily_count = 1;
            }

            $total_doses_today += $valid_daily_count;
            $taken_doses_log[$med->ID] = $count; // Number taken

            // Build visual list
            if (is_array($dose_times)) {
                foreach ($dose_times as $dt) {
                    // Same check for visual list? 
                    // Ideally yes, but maybe we just mark them as "skipped/past" or hide them?
                    // For stats consistency, we track them.
                    $dt_ts_today = strtotime($today_date . ' ' . $dt['time']);

                    // Logic: If retroactive, don't count it in 'Pending' logic either
                    if ($dt_ts_today < $med_created_ts) {
                        continue;
                    }

                    $scheduled_doses[] = array(
                        'med_id' => $med->ID,
                        'title' => $med->post_title,
                        // Logic to mark taken: if count > 0, first one is taken, etc. simple FIFO
                        'is_taken' => ($count > 0),
                        'count_ref' => &$count, // decremented below
                        'time' => $dt['time']
                    );
                    if ($count > 0)
                        $count--;
                }
            }
        }
    }
}

// Calculate Stats
$taken_count = 0;
foreach ($scheduled_doses as $sd) {
    if ($sd['is_taken'])
        $taken_count++;
}
$pending_count = count($scheduled_doses) - $taken_count;
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
                <h1 class="text-lg font-bold"><?php echo esc_html($member->post_title); ?></h1>
                <p class="text-xs text-secondary">
                    <?php echo esc_html(get_post_meta($member->ID, 'relation', true)); ?>
                    <?php
                    $email = get_post_meta($member->ID, 'email', true);
                    if ($email)
                        echo ' • ' . esc_html($email);
                    ?>
                </p>
            </div>
            <div
                class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-600 to-blue-800 flex items-center justify-center text-sm font-bold text-white">
                <?php echo strtoupper(substr($member->post_title, 0, 1)); ?>
            </div>
        </header>

        <main class="flex flex-col gap-6 pb-24 p-4">

            <!-- 0. Due / Postponed Reminders for this Member -->
            <?php
            // Identify active reminders specifically for this member's medications
            $member_med_ids = empty($medications) ? array() : array_column($medications, 'ID');

            $active_reminders = array();
            if (!empty($member_med_ids)) {
                $rem_query = new WP_Query(array(
                    'post_type' => 'reminder_log',
                    'posts_per_page' => -1,
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => 'medication_id',
                            'value' => $member_med_ids,
                            'compare' => 'IN'
                        ),
                        array(
                            'relation' => 'OR',
                            array('key' => 'status', 'value' => 'pending'),
                            array('key' => 'status', 'value' => 'postponed')
                        )
                    )
                ));

                $current_ts = current_time('timestamp');
                foreach ($rem_query->posts as $rem) {
                    $st = get_post_meta($rem->ID, 'status', true);
                    $med_id = get_post_meta($rem->ID, 'medication_id', true);
                    $sched_ts = get_post_meta($rem->ID, 'scheduled_datetime', true);
                    $sched_date = date('Y-m-d', $sched_ts);
                    $sched_time = date('H:i', $sched_ts);

                    // CHECK IF DOSE ALREADY LOGGED for this medication + date + approximate time
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
                    // Additional granularity: check if log_time matches scheduled time (within tolerance)
                    if (!empty($existing_log)) {
                        $log_time = get_post_meta($existing_log[0]->ID, 'log_time', true);
                        // If log time is within 2 hours of scheduled time, consider it the same slot
                        if ($log_time) {
                            $log_ts = strtotime($sched_date . ' ' . $log_time);
                            $time_diff = abs($sched_ts - $log_ts);
                            if ($time_diff < 7200) { // 2 hours tolerance
                                continue; // Skip this reminder, dose already logged
                            }
                        } else {
                            // Log exists but no time - assume it covers this slot
                            continue;
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
            }
            ?>

            <?php if (!empty($active_reminders)): ?>
                <section class="mb-4">
                    <h2 class="text-sm font-semibold text-danger uppercase tracking-wider mb-2 animate-pulse">
                        Due / Postponed
                    </h2>
                    <div class="flex flex-col gap-3">
                        <?php foreach ($active_reminders as $rem):
                            $med_id = get_post_meta($rem->ID, 'medication_id', true);
                            $sched_ts = get_post_meta($rem->ID, 'scheduled_datetime', true);
                            $status = get_post_meta($rem->ID, 'status', true);
                            $med_title = get_the_title($med_id);
                            $dosage = get_post_meta($med_id, 'dosage', true);
                            ?>
                            <div class="card p-4 border-l-4 border-l-red-500 bg-red-500/5"
                                id="reminder-<?php echo $rem->ID; ?>">
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <h3 class="font-bold text-lg text-red-400">
                                            <?php echo esc_html($med_title); ?>
                                        </h3>
                                        <p class="text-sm text-secondary">
                                            <?php echo esc_html($dosage); ?> •
                                            <span class="font-mono"><?php echo date('h:i A', $sched_ts); ?></span>
                                            <?php if ($status === 'postponed'): ?>
                                                <span class="text-xs text-yellow-500 ml-2">(Postponed)</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <!-- No Actions for Member View currently requested, OR should manager act? 
                                     User request: "Actions... Taken/Skipped/Postponed... show in member detail page"
                                     Implies actions happen here too? 
                                     Assuming Yes, adding actions similar to dashboard but without modal injection (using simple prompt if needed or just skip logic for now to avoid complexity, 
                                     BUT user asked for consistency. Let's add basic buttons. Postpone might fail without modal content. 
                                     For now, just display status implies read-only? 
                                     Actually, "Actions: Taken/Skipped/Postponed" were listed as general system rules.
                                     If I am managing a member, I should be able to click Taken.
                                     I'll add the buttons. Logic for Postpone will use simple fallback if modal missing or I can inject modal here too.
                                     I will SKIP the Postpone button here to avoid JS error if Modal HTML is missing, 
                                     or just add Taken/Skip.
                                -->
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
                        // Precise Calculation used in View-Self
                        // 1. Get Logs for these meds for today
                        $daily_logs = get_posts(array(
                            'post_type' => 'dose_log',
                            'posts_per_page' => -1,
                            'meta_query' => array(
                                array('key' => 'log_date', 'value' => $today_date),
                                array('key' => 'medication_id', 'value' => $member_med_ids, 'compare' => 'IN')
                            )
                        ));

                        $count_taken = 0;
                        $count_skipped = 0;
                        foreach ($daily_logs as $l) {
                            $s = get_post_meta($l->ID, 'status', true);
                            if ($s === 'taken')
                                $count_taken++;
                            if ($s === 'skipped')
                                $count_skipped++;
                        }

                        // Static Total (calculated previously as $total_doses_today)
                        $static_total = $total_doses_today;

                        // Strict Calculate
                        $denominator = $total_doses_today;

                        $text_color = ($count_taken > 0) ? 'text-success' : 'text-secondary';
                        ?>
                        <span class="<?php echo $text_color; ?>"><?php echo $count_taken; ?></span>
                        <span class="text-secondary text-2xl">/</span>
                        <span class="text-secondary text-2xl"><?php echo $denominator; ?></span>
                        <span class="text-xs text-secondary ml-1">(Taken / Total)</span>
                    </div>
                    <?php
                    $percent = ($denominator > 0) ? round(($count_taken / $denominator) * 100) : 0;
                    ?>
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
                            $med_created_ts = strtotime($med->post_date);
                            $stock = get_post_meta($med->ID, 'stock_quantity', true);
                            $s_type = get_post_meta($med->ID, 'schedule_type', true);

                            $dose_times_arr = get_post_meta($med->ID, 'dose_times', true);
                            if (!is_array($dose_times_arr) || empty($dose_times_arr)) {
                                $dose_times_arr = array(array('time' => '', 'dosage' => get_post_meta($med->ID, 'dosage', true)));
                            }

                            // Retrieve ALL logs for this medication for today
                            $med_logs_today = get_posts(array(
                                'post_type' => 'dose_log',
                                'posts_per_page' => -1,
                                'meta_query' => array(
                                    'relation' => 'AND',
                                    array('key' => 'medication_id', 'value' => $med->ID),
                                    array('key' => 'log_date', 'value' => $today_date)
                                ),
                                'orderby' => 'ID',
                                'order' => 'ASC'
                            ));

                            foreach ($dose_times_arr as $index => $dt):
                                $time_str = isset($dt['time']) ? $dt['time'] : '';
                                $dosage_val = isset($dt['dosage']) ? $dt['dosage'] : '';
                                if (!$dosage_val)
                                    $dosage_val = '1';

                                // Calculate today's timestamp for this dose time (for start date comparison)
                                $dt_ts_today = $time_str ? strtotime($today_date . ' ' . $time_str) : strtotime($today_date);

                                $slot_status = 'pending';
                                $log_for_slot = isset($med_logs_today[$index]) ? $med_logs_today[$index] : null;

                                if ($log_for_slot) {
                                    $log_status = get_post_meta($log_for_slot->ID, 'status', true);
                                    if ($log_status === 'taken')
                                        $slot_status = 'taken';
                                    elseif ($log_status === 'skipped')
                                        $slot_status = 'skipped';
                                    elseif ($log_status === 'postponed')
                                        $slot_status = 'postponed';
                                    else
                                        $slot_status = 'taken';
                                }

                                if ($s_type === 'as_needed')
                                    $slot_status = 'as_needed';
                                ?>
                                <div class="card p-4">
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
                                            class="text-xs text-primary bg-blue-500/10 px-2 py-1 rounded">
                                            Edit
                                        </a>
                                    </div>

                                    <div class="flex justify-between items-end mt-2">
                                        <div class="text-xs">
                                            <?php if ($slot_status === 'taken'): ?>
                                                <span class="text-success font-bold">Taken</span>
                                            <?php elseif ($slot_status === 'skipped'): ?>
                                                <span class="text-warning font-bold">Skipped</span>
                                            <?php elseif ($slot_status === 'postponed'): ?>
                                                <span class="text-orange-400 font-bold">Postponed</span>
                                            <?php elseif ($dt_ts_today < $med_created_ts): ?>
                                                <span class="text-secondary opacity-50 italic">Before Start Date</span>
                                            <?php else: ?>
                                                <span class="text-secondary italic">Pending</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($slot_status === 'taken' || $slot_status === 'skipped'): ?>
                                            <!-- Done -->
                                        <?php elseif ($dt_ts_today < $med_created_ts): ?>
                                            <!-- Retroactive: Show nothing -->
                                        <?php else: ?>
                                            <a href="<?php echo esc_url(add_query_arg('med_id', $med->ID, home_url('/dose-logger'))); ?>"
                                                class="btn btn-primary py-1 px-3 text-xs w-auto rounded-full">
                                                Take Now
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
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

                            // Simple date formatting
                            $display_date = date('M j', strtotime($m_date));

                            // Badge
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
                                    <p class="font-bold text-sm"><?php echo get_the_title($mid); ?></p>
                                    <p class="text-xs text-secondary"><?php echo $display_date . ' at ' . $m_time; ?></p>
                                </div>
                                <span class="text-xs font-bold <?php echo $badge_class; ?> px-2 py-1 rounded">
                                    <?php echo $status_label; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </section>

            <!-- POSTPONE MODAL -->
            <div id="postpone-modal"
                class="fixed inset-0 bg-black/80 z-50 hidden flex items-center justify-center backdrop-blur-sm p-4">
                <div
                    class="card w-full max-w-sm p-6 bg-gray-900 border border-gray-800 shadow-2xl relative animate-fade-in">
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
                        <button onclick="closePostponeModal()"
                            class="btn text-gray-400 hover:text-white">Cancel</button>
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

                    // Validation: Must be in future
                    const nowSec = Math.floor(Date.now() / 1000);
                    if (target <= nowSec) {
                        alert('Please select a future time.');
                        return;
                    }

                    handleReminderAction(id, 'postpone', { postpone_until: target });
                    closePostponeModal();
                }
            </script>

        </main>
    </div>
</div>

<?php get_footer(); ?>