<?php
/**
 * Template Name: Dose Logger
 *
 * @package PillPalNow
 */

// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user_id = get_current_user_id();
$med_id = isset($_GET['med_id']) ? intval($_GET['med_id']) : 0;
$medication = null;

if ($med_id) {
    $medication = get_post($med_id);
    if ($medication && $medication->post_type !== 'medication') {
        $medication = null;
    }
    // STRICT ISOLATION
    if ($medication) {
        $assigned = get_post_meta($medication->ID, 'assigned_user_id', true);
        if ((int) $assigned !== $current_user_id) {
            $medication = null; // Hide if not assigned to me
        }
    }
}

// If no specific med selected, get the first one or show selection (Simplified: Get first for demo if available)
if (!$medication) {
    $args = array(
        'post_type' => 'medication',
        'posts_per_page' => 1,
        'author' => $current_user_id // Check if author support is enabled for meds, otherwise retrieve all? Assuming user owns meds.
    );
    // Note: If 'medication' CPT doesn't support 'author' natively in register_cpt, we might need to filter by meta or assume global.
    // For this project, let's assume meds are user-specific via author or meta.
    // Let's rely on 'author' since we registered it with 'capability_type' => 'post' but didn't explicitly remove author support.
    $user_meds = get_posts($args);
    if (!empty($user_meds)) {
        $medication = $user_meds[0];
    }
}

get_header();
?>

<div class="app-container flex-col justify-between" style="min-height: 100vh;">

    <!-- Top Navigation -->

    <div class="container flex-1 flex flex-col justify-between">

        <div class="container flex-1 flex flex-col justify-between">
            <!-- Header -->
            <header class="app-header">
                <a href="<?php echo home_url('/dashboard'); ?>" class="flex items-center text-secondary">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </a>
                <h1 class="text-lg font-bold">
                    <?php esc_html_e('Dose Reminder', 'pillpalnow'); ?>
                </h1>
                <div style="width: 24px;"></div>
            </header>

            <main class="flex-1 flex flex-col items-center justify-center p-6 gap-6 relative">

                <?php if (isset($_GET['error']) && $_GET['error'] === 'already_taken'): ?>
                    <div class="card p-4 border-l-4 border-l-red-500 bg-red-500/10 mb-4 animate-fade-in w-full max-w-md">
                        <div class="flex items-center gap-3">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" class="text-red-400">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <p class="text-red-400 font-semibold">One action per dose allowed. This dose has already been
                                logged.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($medication):
                    $dosage = get_post_meta($medication->ID, 'dosage', true);
                    $instructions = get_post_meta($medication->ID, 'instructions', true); // Assuming ACF or custom fields
                    ?>

                    <!-- Icon/Visual -->
                    <div class="rounded-full bg-blue-500/10 p-8 mb-4 border border-blue-500/20"
                        style="background: rgba(37, 99, 235, 0.1);">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" class="text-primary"
                            stroke="currentColor" stroke-width="1.5">
                            <path d="M10.5 20.5l10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"></path>
                            <path d="m8.5 8.5 7 7"></path>
                        </svg>
                    </div>

                    <div class="text-center w-full">
                        <h2 class="text-2xl font-bold mb-1">
                            <?php echo esc_html($medication->post_title); ?>
                        </h2>
                        <p class="text-lg text-secondary mb-4">
                            <?php echo esc_html($dosage ? $dosage : 'Standard Dose'); ?>
                        </p>

                        <?php
                        $scheduled_time_raw = isset($_GET['scheduled_time']) ? $_GET['scheduled_time'] : '';
                        $display_time = $scheduled_time_raw ? date('g:i A', strtotime($scheduled_time_raw)) : date('g:i A');
                        ?>
                        <div class="inline-block bg-card border border-border px-4 py-2 rounded-lg mb-6">
                            <span class="text-danger font-bold flex items-center gap-2">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.5">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                                <?php echo $display_time; ?>
                            </span>
                        </div>

                        <?php if (!empty($instructions) || !empty($medication->post_content)): ?>
                            <div class="card bg-gray-900 border-none">
                                <h3 class="text-sm font-semibold text-secondary mb-2 uppercase tracking-wide">
                                    <?php esc_html_e('Instructions', 'pillpalnow'); ?>
                                </h3>
                                <p class="text-sm leading-relaxed">
                                    <?php echo !empty($instructions) ? esc_html($instructions) : wp_trim_words($medication->post_content, 20); ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <!-- Notes Field (Added as per requirement) -->
                        <div class="mt-4 w-full">
                            <label for="dose-notes"
                                class="text-sm font-semibold text-secondary mb-2 block text-left uppercase tracking-wide">
                                <?php esc_html_e('Notes (Optional)', 'pillpalnow'); ?>
                            </label>
                            <textarea id="dose-notes" form="dose-form" name="notes"
                                class="w-full bg-card border border-gray-700 rounded-lg p-3 text-sm text-white focus:outline-none focus:border-primary"
                                rows="2" placeholder="<?php esc_attr_e('Add any notes here...', 'pillpalnow'); ?>"></textarea>
                        </div>

                    </div>

                <?php else: ?>
                    <div class="text-center">
                        <h2 class="text-xl font-bold mb-4">
                            <?php esc_html_e('No Medication Selected', 'pillpalnow'); ?>
                        </h2>
                        <a href="<?php echo home_url('/dashboard'); ?>" class="btn btn-primary">
                            <?php esc_html_e('Go to Dashboard', 'pillpalnow'); ?>
                        </a>
                    </div>
                <?php endif; ?>

            </main>

            <!-- Action Buttons -->
            <?php if ($medication): ?>
                <form id="dose-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"
                    class="p-6 flex flex-col gap-3 pb-8 bg-gray-900/50 backdrop-blur-sm">
                    <input type="hidden" name="action" value="pillpalnow_log_dose">
                    <?php wp_nonce_field('pillpalnow_dose_log_action', 'pillpalnow_dose_log_nonce'); ?>
                    <input type="hidden" name="medication_id" value="<?php echo esc_attr($medication->ID); ?>">
                    <input type="hidden" name="date" value="<?php echo date('Y-m-d'); ?>">
                    <input type="hidden" name="time" value="<?php echo $scheduled_time_raw ?: date('H:i:s'); ?>">
                    <input type="hidden" name="dose_index"
                        value="<?php echo isset($_GET['dose_index']) ? intval($_GET['dose_index']) : -1; ?>">
                    <!-- Status field handled by button value or JS? Standard form submission uses value of clicked submit button -->

                    <!-- Taken Button -->
                    <button type="submit" name="status" value="taken"
                        class="btn btn-primary text-lg py-4 h-14 shadow-lg w-full"
                        style="background-color: var(--success-color); box-shadow: 0 4px 12px rgba(34, 197, 94, 0.4);">
                        <svg class="mr-2 w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <?php esc_html_e('Taken', 'pillpalnow'); ?>
                    </button>

                    <div class="grid grid-cols-2 gap-3">

                        <!-- Skip Button -->
                        <button type="submit" name="status" value="skipped"
                            class="btn btn-secondary border-warning text-warning h-12 text-base w-full">
                            <svg class="mr-2 w-4 h-4" viewBox="0 0 24 24" fill="currentColor" stroke="none">
                                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                            </svg>
                            <?php esc_html_e('Skip', 'pillpalnow'); ?>
                        </button>

                        <!-- Postpone Button (15 min default) -->
                        <input type="hidden" name="postpone_time_ts" value="<?php echo current_time('timestamp') + 900; ?>">
                        <button type="submit" name="status" value="postponed"
                            class="btn btn-secondary h-12 text-base w-full flex items-center justify-center">
                            <svg class="mr-2 w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            <?php esc_html_e('Postpone (15m)', 'pillpalnow'); ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

    </div>

    <?php get_footer(); ?>