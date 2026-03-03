<?php
/**
 * Template Name: Add Family Member
 *
 * @package PillPalNow
 */

// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

get_header();

$current_user_id = get_current_user_id();

// --- Handle Edit Mode ---
$edit_mode = false;
$member_id = 0;
$name_val = '';
$relation_val = '';
$email_val = '';

if (isset($_GET['edit_member'])) {
    $member_id = intval($_GET['edit_member']);
    $member_post = get_post($member_id);

    // Security check
    if ($member_post && $member_post->post_author == $current_user_id && $member_post->post_type === 'family_member') {
        $edit_mode = true;
        $name_val = $member_post->post_title;
        $relation_val = get_post_meta($member_id, 'relation', true);
        $email_val = get_post_meta($member_id, 'email', true);
    }
}

// --- Fetch Existing Members ---
$existing_members = get_posts(array(
    'post_type' => 'family_member',
    'posts_per_page' => -1,
    'author' => $current_user_id,
    'orderby' => 'title',
    'order' => 'ASC'
));

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
            <h1 class="text-lg font-bold">
                <?php echo $edit_mode ? 'Edit Family Member' : 'Manage Family'; ?>
            </h1>
            <div style="width: 24px;"></div> <!-- Spacer -->
        </header>

        <main class="p-4 flex flex-col gap-8 pb-24">

            <?php
            // Check if invitation was sent
            if (isset($_GET['invitation_sent']) && $_GET['invitation_sent'] === '1'):
                $new_member_name = isset($_GET['member_name']) ? sanitize_text_field(urldecode($_GET['member_name'])) : '';
                $new_member_email = isset($_GET['member_email']) ? sanitize_email(urldecode($_GET['member_email'])) : '';
                ?>
                <!-- Invitation Sent Success Banner -->
                <div
                    class="bg-gradient-to-r from-green-900/50 to-emerald-900/50 rounded-xl p-6 border border-green-500/50 shadow-lg">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 w-12 h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                </path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-green-400 mb-2">✅ Invitation Sent to
                                <?php echo esc_html($new_member_name); ?>!
                            </h3>
                            <p class="text-gray-300 text-sm">
                                We've sent a secure login link to <strong
                                    class="text-white"><?php echo esc_html($new_member_email); ?></strong>.
                            </p>
                            <p class="text-gray-400 text-xs mt-2">
                                They can click the link in the email to access their account immediately. No password
                                required.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            $is_pro = class_exists('Subscription_Manager') && Subscription_Manager::is_pro_user($current_user_id);
            $can_add = class_exists('Subscription_Manager') && Subscription_Manager::can_add_family_member($current_user_id);
            $member_count = class_exists('Subscription_Manager') ? Subscription_Manager::get_family_member_count($current_user_id) : count($existing_members);
            ?>

            <!-- Family Member Counter -->
            <div class="bg-gray-800/50 rounded-lg p-4 border border-gray-700">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-white">Family Members</h3>
                        <p class="text-sm text-gray-400">
                            <?php echo $member_count; ?> members
                            <?php if ($is_pro): ?>
                                (Unlimited)
                            <?php else: ?>
                                (Limit:
                                <?php echo class_exists('Subscription_Manager') ? Subscription_Manager::MAX_FREE_FAMILY_MEMBERS : 5; ?>)
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($is_pro): ?>
                        <span class="bg-purple-600 text-white text-xs font-bold px-3 py-1 rounded-full">PRO ACTIVE</span>
                    <?php else: ?>
                        <span class="bg-gray-600 text-white text-xs font-bold px-3 py-1 rounded-full">FREE PLAN</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$can_add && !$edit_mode): ?>
                <div class="bg-yellow-900/30 border border-yellow-700/50 p-4 rounded-lg flex items-center gap-3">
                    <svg class="w-6 h-6 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <p class="text-yellow-200 font-bold">Limit Reached</p>
                        <p class="text-yellow-400/80 text-sm">You have reached the maximum number of family members for your
                            plan. Upgrade to Pro for unlimited members.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($can_add || $edit_mode): ?>
                <!-- Form Section -->
                <section>
                    <h2 class="text-xl font-bold mb-4"><?php echo $edit_mode ? 'Edit Details' : 'Add New Member'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                        class="flex flex-col gap-6">
                        <input type="hidden" name="action" value="pillpalnow_add_family_member">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">
                        <?php endif; ?>
                        <?php wp_nonce_field('pillpalnow_family_action', 'pillpalnow_family_nonce'); ?>

                        <div class="form-group">
                            <label class="block text-sm font-semibold text-secondary mb-2">
                                <?php esc_html_e('Name', 'pillpalnow'); ?>
                            </label>
                            <input type="text" name="family_name" class="input-field w-full"
                                value="<?php echo esc_attr($name_val); ?>" placeholder="e.g. Mom, Dad, Alice" required>
                        </div>

                        <div class="form-group">
                            <label class="block text-sm font-semibold text-secondary mb-2">
                                <?php esc_html_e('Relation', 'pillpalnow'); ?>
                            </label>
                            <input type="text" name="relation" class="input-field w-full"
                                value="<?php echo esc_attr($relation_val); ?>" placeholder="e.g. Mother, Son, Wife"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="block text-sm font-semibold text-secondary mb-2">
                                <?php esc_html_e('Email ID (Optional)', 'pillpalnow'); ?>
                            </label>
                            <input type="email" name="email" class="input-field w-full"
                                value="<?php echo esc_attr($email_val); ?>" placeholder="e.g. email@example.com">
                            <p class="text-xs text-secondary mt-1">
                                <?php esc_html_e('If they are registered, we will link their account.', 'pillpalnow'); ?>
                            </p>
                        </div>

                        <?php if ($edit_mode && !empty($email_val)):
                            // Check if a generated link is returned via GET
                            $generated_link = isset($_GET['view_magic_link']) ? esc_url(urldecode($_GET['view_magic_link'])) : '';
                            ?>
                            <div class="p-4 bg-gray-800 rounded-lg border border-gray-700 mt-2">
                                <h3 class="text-sm font-bold text-white mb-3">Magic Login Link</h3>

                                <?php if ($generated_link): ?>
                                    <div class="mb-3">
                                        <label class="text-xs text-gray-400">Generated Link (Valid for 15 mins):</label>
                                        <div class="flex gap-2 mt-1">
                                            <input type="text" value="<?php echo $generated_link; ?>" readonly
                                                class="input-field w-full text-sm font-mono bg-gray-900" onclick="this.select()">
                                            <a href="<?php echo $generated_link; ?>" target="_blank"
                                                class="btn btn-secondary text-xs whitespace-nowrap px-3 flex items-center">Open</a>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="flex flex-wrap gap-2">
                                    <!-- View/Copy Button -->
                                    <button type="submit" form="view-magic-form" class="btn btn-outline text-xs px-3 py-2">
                                        👁️ View/Copy Link
                                    </button>

                                    <!-- Resend Email Button -->
                                    <button type="submit" form="resend-magic-form" class="btn btn-outline text-xs px-3 py-2">
                                        ✉️ Resend Email
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

        <!-- Permissions Section -->
        <div class="p-4 bg-gray-800 rounded-lg border border-gray-700">
            <h3 class="text-sm font-bold text-white mb-3"><?php esc_html_e('Member Permissions', 'pillpalnow'); ?></h3>
            <div class="space-y-3">
                <?php
                $permissions = array(
                    'pillpalnow_allow_add' => __('Allow Add Medication', 'pillpalnow'),
                    'pillpalnow_allow_edit' => __('Allow Edit Medication', 'pillpalnow'),
                    'pillpalnow_allow_delete' => __('Allow Delete Medication', 'pillpalnow'),
                    'pillpalnow_allow_history' => __('Allow View History', 'pillpalnow'),
                    'pillpalnow_allow_refill_logs' => __('Allow View Refill Logs', 'pillpalnow'),
                    'pillpalnow_allow_notifications' => __('Allow Notifications', 'pillpalnow'),
                );

                // Fetch linked user ID if editing
                $perm_linked_user_id = 0;
                if ($edit_mode && !empty($member_id)) {
                    $perm_linked_user_id = get_post_meta($member_id, 'linked_user_id', true);
                }

                foreach ($permissions as $key => $label):
                    // Default to 0 if not set or new member (parents usually want to restrict by default?)
                    // Actually, defaults in PillPalNow_Permissions might be different. 
                    // But let's assume explicit settings.
                    $is_allowed = '0';
                    if ($perm_linked_user_id) {
                        $is_allowed = get_user_meta($perm_linked_user_id, $key, true);
                    } elseif (!$edit_mode) {
                        // Defaults for NEW members (Optional: Set true or false)
                        $is_allowed = '1'; // Default to allowed for convenience? User can uncheck.
                    }
                    ?>
                    <div class="flex items-center justify-between">
                        <label class="text-sm text-gray-300"><?php echo esc_html($label); ?></label>
                        <label class="switch relative inline-block w-10 h-5">
                            <input type="checkbox" name="pillpalnow_permissions[<?php echo $key; ?>]" value="1"
                                class="opacity-0 w-0 h-0" <?php checked('1', $is_allowed); ?>>
                            <span
                                class="slider round absolute cursor-pointer top-0 left-0 right-0 bottom-0 bg-gray-600 transition-all duration-300 rounded-full before:absolute before:content-[''] before:h-3 before:w-3 before:left-1 before:bottom-1 before:bg-white before:transition-all before:duration-300 before:rounded-full"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-full">
            <?php echo $edit_mode ? 'Update Family Member' : 'Add Family Member'; ?>
        </button>

        <?php if ($edit_mode): ?>
            <a href="<?php echo home_url('/add-family-member'); ?>" class="btn btn-outline w-full text-center">
                Cancel
            </a>
        <?php endif; ?>

        </form>
        </section>
    <?php endif; ?>

    <!-- Existing Members List -->
    <?php if (!empty($existing_members) && !$edit_mode): ?>
        <section>
            <h2 class="text-xl font-bold mb-4">Existing Members</h2>
            <div class="space-y-3">
                <?php foreach ($existing_members as $member):
                    $rel = get_post_meta($member->ID, 'relation', true);
                    $edit_link = add_query_arg('edit_member', $member->ID, home_url('/add-family-member'));

                    $delete_url = admin_url('admin-post.php');
                    $delete_url = add_query_arg(array(
                        'action' => 'pillpalnow_delete_family_member',
                        'member_id' => $member->ID,
                        'nonce' => wp_create_nonce('pillpalnow_delete_family_nonce')
                    ), $delete_url);
                    ?>
                    <div class="bg-gray-800 rounded-lg p-4 flex justify-between items-center border border-gray-700">
                        <div>
                            <h3 class="font-bold text-white"><?php echo esc_html($member->post_title); ?></h3>
                            <p class="text-sm text-gray-400"><?php echo esc_html($rel); ?></p>
                        </div>
                        <div class="flex items-center gap-3">
                            <!-- All users can edit and delete their family members -->
                            <a href="<?php echo esc_url($edit_link); ?>" class="text-blue-400 hover:text-blue-300 p-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                    </path>
                                </svg>
                            </a>
                            <button type="button" class="text-red-400 hover:text-red-300 p-2 delete-member-btn"
                                data-member-name="<?php echo esc_attr($member->post_title); ?>"
                                data-delete-url="<?php echo esc_url($delete_url); ?>">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                    </path>
                                </svg>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    </main>

    <?php if ($edit_mode && !empty($email_val)): ?>
        <!-- Hidden Forms for Magic Link Actions -->
        <form id="resend-magic-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pillpalnow_resend_magic">
            <input type="hidden" name="email" value="<?php echo esc_attr($email_val); ?>">
            <!-- Redirect back to this edit page -->
            <input type="hidden" name="redirect_to_url"
                value="<?php echo esc_url(add_query_arg('edit_member', $member_id, home_url('/add-family-member'))); ?>">
            <?php wp_nonce_field('pillpalnow_resend_magic_action', 'pillpalnow_resend_magic_nonce'); ?>
        </form>

        <form id="view-magic-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pillpalnow_view_magic_link">
            <input type="hidden" name="email" value="<?php echo esc_attr($email_val); ?>">
            <input type="hidden" name="redirect_to_url"
                value="<?php echo esc_url(add_query_arg('edit_member', $member_id, home_url('/add-family-member'))); ?>">
            <?php wp_nonce_field('pillpalnow_view_magic_action', 'pillpalnow_view_magic_nonce'); ?>
        </form>
    <?php endif; ?>

</div>

</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal"
    style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999; background: rgba(0,0,0,0.85); align-items: center; justify-content: center; padding: 16px;">
    <div
        style="background: #1f2937; border-radius: 16px; padding: 24px; max-width: 380px; width: 100%; border: 1px solid #4b5563; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
        <div style="text-align: center; margin-bottom: 24px;">
            <div
                style="width: 64px; height: 64px; margin: 0 auto 16px; background: rgba(239,68,68,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <svg style="width: 32px; height: 32px; color: #ef4444;" fill="none" stroke="currentColor"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                    </path>
                </svg>
            </div>
            <h3 style="font-size: 20px; font-weight: bold; color: white; margin-bottom: 8px;">Delete Family Member?</h3>
            <p style="color: #9ca3af;">
                Are you sure you want to delete <span id="delete-member-name"
                    style="font-weight: 600; color: white;"></span>?
                This action cannot be undone.
            </p>
        </div>
        <div style="display: flex; gap: 12px;">
            <button type="button" id="cancel-delete-btn"
                style="flex: 1; padding: 12px 16px; background: #374151; color: white; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;">
                Cancel
            </button>
            <a href="#" id="confirm-delete-btn"
                style="flex: 1; padding: 12px 16px; background: #dc2626; color: white; border: none; border-radius: 8px; font-weight: 500; text-align: center; text-decoration: none; cursor: pointer;">
                Delete
            </a>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('delete-modal');
        var memberNameSpan = document.getElementById('delete-member-name');
        var confirmBtn = document.getElementById('confirm-delete-btn');
        var cancelBtn = document.getElementById('cancel-delete-btn');

        if (!modal || !memberNameSpan || !confirmBtn || !cancelBtn) {
            console.error('Delete modal elements not found!');
            return;
        }

        // Open modal when delete button is clicked
        var deleteButtons = document.querySelectorAll('.delete-member-btn');
        deleteButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var memberName = this.getAttribute('data-member-name');
                var deleteUrl = this.getAttribute('data-delete-url');

                memberNameSpan.textContent = memberName;
                confirmBtn.href = deleteUrl;
                modal.style.display = 'flex';
            });
        });

        // Close modal when cancel is clicked
        cancelBtn.addEventListener('click', function () {
            modal.style.display = 'none';
        });

        // Close modal when clicking outside
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                modal.style.display = 'none';
            }
        });
    });
</script>

<?php get_footer(); ?>