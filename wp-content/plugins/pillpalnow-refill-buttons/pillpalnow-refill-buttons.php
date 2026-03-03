<?php
/**
 * Plugin Name: PillPalNow Refill Buttons
 * Description: Adds dynamic refill buttons to the page-refills.php file
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL2
 */

// Enqueue dynamic refill buttons script
function pillpalnow_enqueue_refill_buttons_script() {
    if (is_page('refills')) {
        wp_enqueue_script('pillpalnow-dynamic-refill-buttons', plugins_url('dynamic-refill-buttons.js', __FILE__), array('jquery'), '1.0.0', true);
        wp_enqueue_script('pillpalnow-refill-actions', plugins_url('refill-actions.js', __FILE__), array('jquery', 'pillpalnow-dynamic-refill-buttons'), '1.0.0', true);
    }
}
add_action('wp_enqueue_scripts', 'pillpalnow_enqueue_refill_buttons_script');

// Add dynamic refill buttons to medication cards
function pillpalnow_add_refill_buttons_to_medication_cards($content) {
    if (is_page('refills')) {
        // Replace the actions div with our own
        $pattern = '/<!-- Actions -->.*?<!-- \/Actions -->/s';
        $replacement = '<!-- Actions -->
                                    <div class="flex flex-col gap-2">
                                        <?php if ($med["is_low"]): ?>
                                            <p class="text-danger text-xs font-semibold">
                                                ⚠️ Refill Needed
                                            </p>
                                        <?php endif; ?>
                                        
                                        <!-- Manual Refill Button -->
                                        <button class="btn btn-primary btn-sm" onclick="requestRefill(<?php echo $med["id"]; ?>)">
                                            Request Refill
                                        </button>
                                        
                                        <!-- Snooze Button -->
                                        <button class="btn btn-secondary btn-sm" onclick="snoozeRefill(<?php echo $med["id"]; ?>)">
                                            Snooze (24h)
                                        </button>
                                    </div>
                                <!-- /Actions -->';
        
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    return $content;
}
add_filter('the_content', 'pillpalnow_add_refill_buttons_to_medication_cards');
?>
