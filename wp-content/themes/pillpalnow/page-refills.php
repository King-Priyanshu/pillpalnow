<?php
/**
 * Template Name: Refills Page
 *
 * @package PillPalNow
 */

if (!is_user_logged_in()) {
    wp_redirect(home_url('/login'));
    exit;
}

$current_user = wp_get_current_user();
$current_user_id = $current_user->ID;

// PERMISSION CHECK
if (class_exists('PillPalNow_Permissions') && !PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_VIEW_REFILLS, true)) {
    get_header('simple');
    ?>
    <div class="app-container flex-col justify-start" style="min-height: 100vh;">
        <div class="container p-6">
            <div class="bg-red-900/50 border border-red-700/50 text-red-200 px-4 py-3 rounded-lg relative" role="alert">
                <strong class="font-bold">Access Restricted</strong>
                <span class="block sm:inline"> You do not have permission to view refill logs.</span>
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

// --- Data Gathering ---
// --- Data Gathering ---
$grouped_data = array();

// 1. "Self" (Parent) Data
$self_meds = get_posts(array(
    'post_type' => 'medication',
    'posts_per_page' => -1,
    'author' => $current_user_id,
    'post_status' => 'publish',
    'meta_query' => array(
        'relation' => 'OR',
        array('key' => 'assigned_user_id', 'value' => $current_user_id),
        array('key' => 'assigned_to', 'value' => 'Self'),
        array(
            'relation' => 'AND',
            array('key' => 'family_member_id', 'compare' => 'NOT EXISTS'),
            array('key' => 'assigned_user_id', 'compare' => 'NOT EXISTS')
        )
    )
));

$grouped_data[] = array(
    'member_name' => 'My Medications',
    'meds' => pillpalnow_process_meds_for_refills($self_meds, 'Self')
);

// 2. Family Members Data
$family_members = get_posts(array(
    'post_type' => 'family_member',
    'posts_per_page' => -1,
    'author' => $current_user_id
));

foreach ($family_members as $member) {
    // FIX: Removed strict 'assigned_user_id' check to allow family meds to appear
    $meds = get_posts(array(
        'post_type' => 'medication',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(
            array('key' => 'family_member_id', 'value' => $member->ID)
        )
    ));

    if (!empty($meds)) {
        $grouped_data[] = array(
            'member_name' => $member->post_title,
            'meds' => pillpalnow_process_meds_for_refills($meds, $member->post_title)
        );
    }
}

// Helper code to process meds for display
function pillpalnow_process_meds_for_refills($meds, $member_name)
{
    $processed = array();
    foreach ($meds as $med) {
        if (function_exists('pillpalnow_get_remaining_stock')) {
            $stock = (int) pillpalnow_get_remaining_stock($med->ID);
        } else {
            $stock = (int) get_post_meta($med->ID, 'stock_quantity', true);
        }
        $threshold = (int) get_post_meta($med->ID, 'refill_threshold', true);
        $frequency = get_post_meta($med->ID, 'frequency', true);
        $dosage = get_post_meta($med->ID, 'dosage', true);

        // Status Logic - use hardcoded threshold of 7 for consistency
        $effective_threshold = ($threshold > 0) ? $threshold : 7;
        $is_low = ($stock <= $effective_threshold);

        $processed[] = array(
            'id' => $med->ID,
            'title' => $med->post_title,
            'member_name' => $member_name,
            'stock' => $stock,
            'threshold' => $threshold,
            'frequency' => $frequency,
            'dosage' => $dosage,
            'is_low' => $is_low
        );
    }

    // Sort: Low Stock First, then Alphabetical
    usort($processed, function ($a, $b) {
        if ($a['is_low'] !== $b['is_low']) {
            return $a['is_low'] ? -1 : 1;
        }
        return strcmp($a['title'], $b['title']);
    });

    return $processed;
}

get_header('simple');
?>

<div class="app-container flex-col" style="min-height: 100vh;">

    <div class="container p-4 pb-24">

        <!-- Header -->
        <header class="flex justify-between items-center mb-6">
            <h1 class="text-xl font-bold">Refill Alerts</h1>
            <a href="<?php echo home_url('/add-medication'); ?>" class="btn-text text-primary text-sm font-semibold">
                + Add Medication
            </a>
        </header>

        <!-- Recent Refill Alerts -->
        <?php
        $refill_alerts = [];
        $stale_alert_ids = [];

        if (class_exists('PillPalNow_Notifications')) {
            $all_notifs = PillPalNow_Notifications::get_notifications($current_user_id, ['limit' => 20]);

            foreach ($all_notifs as $n) {
                if ($n['type'] === 'refill_low') {
                    // Check if medication is still low (filter out stale alerts)
                    $med_id = $n['medication_id'];
                    if ($med_id && get_post($med_id)) {
                        $current_stock = function_exists('pillpalnow_get_remaining_stock')
                            ? (int) pillpalnow_get_remaining_stock($med_id)
                            : (int) get_post_meta($med_id, 'stock_quantity', true);

                        $threshold = (int) get_post_meta($med_id, 'refill_threshold', true);
                        $effective_threshold = ($threshold > 0) ? $threshold : 7;

                        // Only show alert if medication is still low
                        if ($current_stock <= $effective_threshold) {
                            $refill_alerts[] = $n;
                        } else {
                            // Mark stale alert for cleanup
                            $stale_alert_ids[] = $n['id'];
                        }
                    } else {
                        // Medication deleted, mark alert as stale
                        $stale_alert_ids[] = $n['id'];
                    }
                }
            }

            // Auto-cleanup stale alerts in the background
            if (!empty($stale_alert_ids)) {
                PillPalNow_Notifications::soft_delete($stale_alert_ids, $current_user_id);
            }
        }
        ?>

        <?php if (!empty($refill_alerts)): ?>
            <div class="mb-8">
                <h2 class="text-lg font-bold mb-3 text-white">Recent Alerts</h2>
                <div class="space-y-3">
                    <?php foreach (array_slice($refill_alerts, 0, 3) as $alert): ?>
                        <div class="dashboard-card p-4 border-l-4 border-danger flex gap-3 items-center">
                            <div class="text-2xl">⚠️</div>
                            <div>
                                <h4 class="font-bold text-white"><?php echo esc_html($alert['title']); ?></h4>
                                <p class="text-sm text-gray-300"><?php echo esc_html($alert['message']); ?></p>
                                <p class="text-xs text-secondary mt-1">
                                    <?php echo human_time_diff($alert['created_timestamp'], current_time('timestamp')); ?> ago
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Loop through grouped data
        $has_any_meds = false;
        foreach ($grouped_data as $group) {
            if (!empty($group['meds'])) {
                $has_any_meds = true;
            }
        }
        ?>

        <?php if (!$has_any_meds): ?>
            <div class="dashboard-card p-10 text-center">
                <p class="text-secondary mb-4">No medications found.</p>
                <a href="<?php echo home_url('/add-medication'); ?>" class="btn btn-primary">Add Your First Med</a>
            </div>
        <?php else: ?>

            <div class="space-y-8">
                <?php foreach ($grouped_data as $group): ?>
                    <?php if (empty($group['meds']))
                        continue; ?>

                    <div>
                        <h2 class="text-lg font-bold mb-4 text-white pl-1 border-l-4 border-primary ml-1 pl-3">
                            <?php echo esc_html($group['member_name']); ?>
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($group['meds'] as $med):
                                // Styling determine
                                $border_class = $med['is_low'] ? 'border-l-4 border-danger' : 'border-l-4 border-success';
                                $badge_class = $med['is_low'] ? 'text-danger bg-red-500/10' : 'text-success bg-green-500/10';

                                ?>
                                <div class="dashboard-card p-4 relative <?php echo $border_class; ?>">

                                    <!-- Top Row -->
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <h3 class="font-bold text-lg mb-0.5"><?php echo esc_html($med['title']); ?></h3>
                                            <p class="text-xs text-secondary">
                                                <?php echo esc_html($med['frequency']); ?>
                                            </p>
                                        </div>
                                        <span class="text-xs font-bold px-2 py-1 rounded <?php echo $badge_class; ?>">
                                            <?php echo $med['stock']; ?> Left
                                        </span>
                                    </div>

                                    <!-- Progress Bar (Simple) -->
                                    <div class="w-full h-1 bg-gray-700 rounded-full mb-4 mt-2 overflow-hidden">
                                        <?php
                                        $max = max($med['stock'] + 20, 50);
                                        $pct = min(100, ($med['stock'] / $max) * 100);
                                        $color = $med['is_low'] ? 'var(--danger-color)' : 'var(--success-color)';
                                        ?>
                                        <div
                                            style="width: <?php echo $pct; ?>%; background-color: <?php echo $color; ?>; height: 100%;">
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex flex-col gap-2">
                                        <?php if ($med['is_low']): ?>
                                            <p class="text-danger text-xs font-semibold">
                                                ⚠️ Refill Needed
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>
</div>

<?php get_footer(); ?>
