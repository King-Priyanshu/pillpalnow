<?php
/**
 * Template Name: Add Medication
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
if (class_exists('PillPalNow_Permissions') && !PillPalNow_Permissions::check(PillPalNow_Permissions::CAN_ADD_MEDICATION, true)) {
    // DEBUG OUTPUT
    $d_uid = get_current_user_id();
    $d_user = get_userdata($d_uid);
    $d_roles = $d_user ? implode(', ', (array) $d_user->roles) : 'Unknown';
    $d_meta_new = get_user_meta($d_uid, 'pillpalnow_parent_user', true);
    $d_meta_old = get_user_meta($d_uid, 'parent_user_id', true);

    echo "<div style='background:#fef3c7; color:#92400e; padding:15px; margin:20px; border:2px solid #d97706; border-radius:6px; font-family:monospace;'>
          <h3 style='margin:0 0 10px 0; font-weight:bold;'>⛔ PERMISSION CHECK FAILED</h3>
          <strong>User ID:</strong> {$d_uid}<br>
          <strong>Roles:</strong> {$d_roles}<br>
          <strong>pillpalnow_parent_user:</strong> " . var_export($d_meta_new, true) . "<br>
          <strong>parent_user_id:</strong> " . var_export($d_meta_old, true) . "<br>
          <strong>Logic Result:</strong> " . (((int) $d_meta_new > 0 || (int) $d_meta_old > 0) ? 'Treating as Family Member (Restricted)' : 'Should be Primary (Allowed) - Check Logic Error?') . "
          </div>";
    ?>
    <div class="app-container flex-col justify-start" style="min-height: 100vh;">
        <div class="container p-6">
            <div class="bg-red-900/50 border border-red-700/50 text-red-200 px-4 py-3 rounded-lg relative" role="alert">
                <strong class="font-bold">Permission Denied</strong>
                <span class="block sm:inline"> You do not have permission to add medications.</span>
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
?>

<div class="app-container flex-col justify-between" style="min-height: 100vh;">

    <!-- Top Navigation -->

    <div class="container flex-1">

        <div class="container flex-1">
            <!-- Header -->
            <header class="app-header border-b border-gray-800">
                <a href="<?php echo home_url('/dashboard'); ?>" class="flex items-center text-secondary">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5"></path>
                        <path d="M12 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <h1 class="text-lg font-bold">
                    <?php esc_html_e('Add Medication', 'pillpalnow'); ?>
                </h1>
                <div style="width: 24px;"></div> <!-- Spacer -->
            </header>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pillpalnow_add_medication">
                <?php wp_nonce_field('pillpalnow_add_med_action', 'pillpalnow_add_med_nonce'); ?>
                <main class="p-4 flex flex-col gap-8 pb-32 max-w-3xl mx-auto">

                    <!-- SECTON 1: Medication Details -->
                    <section class="space-y-4">
                        <h2 class="text-xl font-bold text-white border-b border-gray-800 pb-2">
                            <?php esc_html_e('Medication Details', 'pillpalnow'); ?>
                        </h2>

                        <!-- Drug Name -->
                        <div class="form-group">
                            <label class="block text-sm font-semibold text-secondary mb-2">
                                <?php esc_html_e('Drug Name', 'pillpalnow'); ?>
                            </label>
                            <div class="relative group">
                                <input type="text" name="post_title"
                                    class="input-field w-full transition-all focus:ring-2 focus:ring-primary"
                                    placeholder="Search or type name..." required autocomplete="off"
                                    id="drug_name_input">
                                <input type="hidden" name="rxcui" id="rxcui_input">
                                <div
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-secondary group-focus-within:text-primary transition-colors">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Instructions -->
                        <div class="form-group">
                            <label class="block text-sm font-semibold text-secondary mb-2 flex items-center gap-2">
                                <?php esc_html_e('Instructions', 'pillpalnow'); ?>
                                <span class="text-xs text-gray-500 bg-gray-800 px-2 py-0.5 rounded cursor-help"
                                    title="Optional notes on how to take the medication">?</span>
                            </label>
                            <textarea name="instructions"
                                class="input-field w-full h-24 resize-none transition-all focus:ring-2 focus:ring-primary"
                                placeholder="E.g using food..."></textarea>
                        </div>
                    </section>


                    <!-- SECTION 2: Schedule -->
                    <section class="space-y-4">
                        <h2 class="text-xl font-bold text-white border-b border-gray-800 pb-2">
                            <?php esc_html_e('Schedule', 'pillpalnow'); ?>
                        </h2>

                        <!-- Frequency -->
                        <label class="block text-sm font-semibold text-secondary mb-2">
                            <?php esc_html_e('Frequency', 'pillpalnow'); ?>
                        </label>
                        <input type="hidden" name="schedule_type" id="schedule_type_input" value="daily">
                        <div class="grid grid-cols-3 gap-3">
                            <button type="button" onclick="setSchedule('daily')" id="btn-daily"
                                class="btn bg-primary text-white border border-primary transition-all p-3 text-center rounded-lg hover:bg-gray-800">
                                Daily
                            </button>
                            <button type="button" onclick="setSchedule('weekly')" id="btn-weekly"
                                class="btn bg-card text-secondary border border-transparent transition-all p-3 text-center rounded-lg hover:bg-gray-800">
                                Weekly
                            </button>
                            <button type="button" onclick="setSchedule('as_needed')" id="btn-as_needed"
                                class="btn bg-card text-secondary border border-transparent transition-all p-3 text-center rounded-lg hover:bg-gray-800">
                                As Needed
                            </button>
                        </div>

                        <!-- Weekly Specific Options -->
                        <div id="weekly-options" class="mt-4 hidden card p-4 border border-gray-800">
                            <label class="block text-sm font-semibold text-secondary mb-3">
                                <?php esc_html_e('Select Days', 'pillpalnow'); ?>
                            </label>
                            <div class="grid grid-cols-7 gap-2 mb-4">
                                <?php
                                $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                foreach ($days as $day): ?>
                                    <div class="relative">
                                        <input type="checkbox" name="weekdays[]" value="<?php echo strtolower($day); ?>"
                                            id="day-<?php echo strtolower($day); ?>" class="peer sr-only">
                                        <label for="day-<?php echo strtolower($day); ?>"
                                            class="block w-full text-center py-2 rounded bg-gray-800 text-secondary border border-gray-700 cursor-pointer transition-all peer-checked:bg-primary peer-checked:text-white peer-checked:border-primary peer-hover:border-gray-600 text-xs font-bold">
                                            <?php echo $day; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <label class="block text-sm font-semibold text-white mb-2">
                                <?php esc_html_e('Start Date', 'pillpalnow'); ?>
                            </label>
                            <input type="date" name="start_date" class="input-field w-full"
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <script>
                            function setSchedule(val) {
                                // Update Input
                                document.getElementById('schedule_type_input').value = val;

                                // Update Buttons
                                const btns = ['daily', 'weekly', 'as_needed'];
                                btns.forEach(type => {
                                    const btn = document.getElementById('btn-' + type);
                                    if (type === val) {
                                        // Active State
                                        btn.className = 'btn bg-primary text-white border border-primary transition-all p-3 text-center rounded-lg hover:bg-gray-800';
                                    } else {
                                        // Inactive State
                                        btn.className = 'btn bg-card text-secondary border border-transparent transition-all p-3 text-center rounded-lg hover:bg-gray-800';
                                    }
                                });

                                // Toggle Weekly Options
                                const weeklyOpts = document.getElementById('weekly-options');
                                if (val === 'weekly' || val === 'as_needed') {
                                    weeklyOpts.classList.remove('hidden');
                                } else {
                                    weeklyOpts.classList.add('hidden');
                                }
                            }
                        </script>

                        <!-- Dose Times -->
                        <div class="form-group">
                            <label class="block text-sm font-semibold text-secondary mb-2">
                                <?php esc_html_e('Dose Times & Amount', 'pillpalnow'); ?>
                            </label>
                            <div class="flex flex-col gap-3 time-container">
                                <!-- Existing Time Item -->
                                <div
                                    class="card p-4 flex justify-between items-center time-item hover:border-gray-700 transition-colors">
                                    <div class="flex items-center gap-4">
                                        <div class="bg-gray-800 p-2.5 rounded-lg text-primary">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                        </div>
                                        <input type="time" name="dose_time[]"
                                            class="bg-transparent border-none text-white font-bold text-2xl focus:ring-0 p-0 cursor-pointer"
                                            value="08:00">
                                    </div>
                                    <div class="flex items-center gap-3 bg-gray-800 rounded-lg p-1.5 px-3">
                                        <input type="number" step="0.5" name="dose_amount[]"
                                            class="bg-transparent border-none text-white font-bold text-lg text-center w-12 p-0 focus:ring-0"
                                            value="1.0">
                                        <span class="text-sm text-secondary font-medium">pill(s)</span>
                                    </div>
                                </div>
                            </div>

                            <button type="button"
                                class="btn w-full mt-3 py-3 border-2 border-dashed border-gray-700 text-secondary hover:border-primary hover:text-primary transition-all rounded-lg add-time-btn">
                                + <?php esc_html_e('Add Another Time', 'pillpalnow'); ?>
                            </button>
                        </div>
                    </section>


                    <!-- SECTION 3: Inventory -->
                    <section class="space-y-4">
                        <h2 class="text-xl font-bold text-white border-b border-gray-800 pb-2">
                            <?php esc_html_e('Inventory', 'pillpalnow'); ?>
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Current Quantity -->
                            <div>
                                <label class="block text-sm font-semibold text-secondary mb-2">
                                    <?php esc_html_e('Current Quantity', 'pillpalnow'); ?>
                                </label>
                                <input type="number" name="stock_quantity" class="input-field w-full" value="30"
                                    min="0">
                                <p class="text-xs text-gray-500 mt-1">Total pills currently on hand.</p>
                            </div>

                            <!-- Refill Threshold -->
                            <div>
                                <label class="block text-sm font-semibold text-secondary mb-2">
                                    <?php esc_html_e('Refill Alert At', 'pillpalnow'); ?>
                                </label>
                                <input type="number" name="refill_threshold" class="input-field w-full" value="7"
                                    min="0">
                                <p class="text-xs text-gray-500 mt-1">Alert when quantity drops below this.</p>
                            </div>

                            <!-- Refills Left (Read Only) -->
                            <div class="opacity-75">
                                <label class="block text-sm font-semibold text-secondary mb-2 flex items-center gap-2">
                                    <?php esc_html_e('Refills Left', 'pillpalnow'); ?>
                                    <span class="text-xs bg-primary/20 text-primary px-2 py-0.5 rounded">Auto</span>
                                </label>
                                <input type="number" name="refills_left"
                                    class="input-field w-full opacity-50 cursor-not-allowed" value="0" readonly>
                                <p class="text-xs text-gray-500 mt-1">Automated. Decrements when stock hits 0.</p>
                            </div>

                            <!-- Refill Size (Hidden configuration for logic) -->
                            <!-- We'll expose this as "Refill Pack Size" so user knows what happens on reset -->
                            <div>
                                <label class="block text-sm font-semibold text-secondary mb-2">
                                    <?php esc_html_e('Refill Pack Size', 'pillpalnow'); ?>
                                </label>
                                <input type="number" name="refill_size" class="input-field w-full" value="30" min="1">
                                <p class="text-xs text-gray-500 mt-1">Quantity to restore when refill is used.</p>
                            </div>
                        </div>
                    </section>


                    <!-- SECTION 4: Assigned To -->
                    <section class="space-y-4">
                        <h2 class="text-xl font-bold text-white border-b border-gray-800 pb-2">
                            <?php esc_html_e('Assigned To', 'pillpalnow'); ?>
                        </h2>

                        <div class="form-group">
                            <label class="block text-sm font-semibold text-secondary mb-2">
                                <?php esc_html_e('Select Member', 'pillpalnow'); ?>
                            </label>
                            <div class="relative">
                                <select name="assigned_to" class="input-field w-full appearance-none cursor-pointer">
                                    <?php
                                    $current_user_id = get_current_user_id();
                                    $current_user = wp_get_current_user();

                                    // Option: Self
                                    echo '<option value="self" selected>' . esc_html($current_user->display_name) . ' (Assigned)</option>';

                                    // Family Members
                                    $family_args = array(
                                        'post_type' => 'family_member',
                                        'posts_per_page' => -1,
                                        'author' => $current_user_id
                                    );
                                    $family_members = get_posts($family_args);

                                    if (!empty($family_members)):
                                        foreach ($family_members as $fm): ?>
                                            <option value="<?php echo esc_attr($fm->ID); ?>">
                                                <?php echo esc_html($fm->post_title); ?>
                                            </option>
                                        <?php endforeach;
                                    endif; ?>
                                </select>
                                <div
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-secondary pointer-events-none">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <path d="M6 9l6 6 6-6" />
                                    </svg>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Select who is taking this medication.</p>
                        </div>
                    </section>

                </main>

                <!-- Floating Area with Save -->
                <div class="fixed bottom-0 left-0 right-0 p-4 bg-gray-900 border-t border-gray-800 mx-auto z-50 backdrop-blur"
                    style="background: rgba(15, 23, 42, 0.95);">
                    <div class="container">
                        <button type="submit" class="btn btn-primary w-full">
                            <?php esc_html_e('Save Medication', 'pillpalnow'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>

    <?php get_footer(); ?>