<?php
/**
 * Template Name: Upgrade Page
 *
 * @package PillPalNow
 */

// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

get_header();
?>

<div class="app-container flex-col justify-between" style="min-height: 100vh;">
    <div class="container flex-1">
        <header class="app-header">
            <a href="<?php echo home_url('/profile'); ?>" class="flex items-center text-secondary">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5"></path>
                    <path d="M12 19l-7-7 7-7"></path>
                </svg>
            </a>
            <h1 class="text-xl font-bold">Upgrade to Pro</h1>
            <div style="width: 24px;"></div>
        </header>

        <main class="p-6 flex flex-col gap-6 pb-24">

            <div class="text-center">
                <div
                    class="w-20 h-20 bg-gradient-to-br from-yellow-400 to-orange-500 rounded-full flex items-center justify-center text-4xl mx-auto mb-4 shadow-lg shadow-orange-500/20">
                    💎
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">PillPalNow Pro</h2>
                <p class="text-gray-400">Unlock the full potential of your medication management.</p>
            </div>

            <div class="card p-6 border border-blue-500/30 bg-blue-900/10">
                <h3 class="font-bold text-lg mb-4 text-blue-300">What's Included:</h3>
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="text-green-400">✓</span>
                        <div>
                            <span class="font-bold text-white">Multi-Device Cloud Sync</span>
                            <p class="text-xs text-gray-400">Keep your data in sync across all your phones and tablets.
                            </p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="text-green-400">✓</span>
                        <div>
                            <span class="font-bold text-white">Family Sharing</span>
                            <p class="text-xs text-gray-400">Manage medications for your entire family in one place.</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="text-green-400">✓</span>
                        <div>
                            <span class="font-bold text-white">PDF Reports</span>
                            <p class="text-xs text-gray-400">Export detailed logs for your doctor.</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="text-green-400">✓</span>
                        <div>
                            <span class="font-bold text-white">Ad-Free Experience</span>
                            <p class="text-xs text-gray-400">Enjoy a cleaner, faster app without interruptions.</p>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="card p-6 text-center">
                <p class="text-secondary text-sm mb-2">Monthly Subscription</p>
                <h3 class="text-3xl font-bold text-white mb-6">$2.99 <span class="text-base font-normal text-gray-400">/
                        month</span></h3>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pillpalnow_upgrade_subscription">
                    <?php wp_nonce_field('pillpalnow_upgrade_nonce', 'pillpalnow_upgrade_nonce'); ?>

                    <button type="submit"
                        class="btn btn-primary w-full py-3 text-lg font-bold shadow-lg shadow-blue-600/30">
                        Subscribe Now
                    </button>
                    <p class="text-xs text-gray-500 mt-4">Auto-renews. Cancel anytime.</p>
                </form>
            </div>

        </main>
    </div>
</div>

<?php get_footer(); ?>