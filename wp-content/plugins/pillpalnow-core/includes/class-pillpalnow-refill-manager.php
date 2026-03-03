<?php
/**
 * Refill Manager
 * 
 * Handles auto-refill logic when a dose is logged.
 * 
 * @package PillPalNow
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Refill_Manager
 */
class PillPalNow_Refill_Manager
{
    /**
     * Initialize
     */
    public function __construct()
    {
        // Hook into dose logging action (which fires AFTER stock calculation)
        add_action('pillpalnow/dose_logged', array($this, 'check_and_process_auto_refill'), 10, 3);
    }

    /**
     * Check and process auto-refill logic
     * 
     * @param int   $medication_id  Medication ID
     * @param int   $dose_log_id    Dose Log ID
     * @param float $dosage_taken   Dosage taken
     */
    public function check_and_process_auto_refill($medication_id, $dose_log_id, $dosage_taken)
    {
        // Get current stock (which was just updated by pillpalnow_recalculate_stock before this hook)
        $current_stock = (float) get_post_meta($medication_id, 'stock_quantity', true);

        // Auto-Refill Logic: If Stock <= 0 AND Refills Left > 0
        if ($current_stock <= 0) {
            $refills_left = (int) get_post_meta($medication_id, 'refills_left', true);

            // Get refill size (or default to 30)
            $refill_size = (int) get_post_meta($medication_id, 'refill_size', true);
            if ($refill_size <= 0) {
                $refill_size = 30; // Default fallback
            }

            if ($refills_left > 0) {
                // Decrement refills
                $refills_left--;
                update_post_meta($medication_id, 'refills_left', $refills_left);

                // Perform Auto-Refill
                update_post_meta($medication_id, '_refill_base_qty', $refill_size);
                update_post_meta($medication_id, '_refill_date', date('Y-m-d'));

                // Clear Refill Snooze/Triggers (Clean Slate)
                delete_post_meta($medication_id, 'refill_snoozed_until');
                delete_post_meta($medication_id, '_refill_triggered');
                delete_post_meta($medication_id, '_refill_alert_sent_date');

                // Clear refill notifications for this medication
                if (class_exists('PillPalNow_Notifications')) {
                    PillPalNow_Notifications::clear_refill_notifications($medication_id, get_post_field('post_author', $medication_id));
                }

                // Recalculate again to reflect the refill
                if (function_exists('pillpalnow_recalculate_stock')) {
                    wp_cache_delete('pillpalnow_stock_' . $medication_id, 'pillpalnow');
                    pillpalnow_recalculate_stock($medication_id);
                }

                /**
                 * Fires when a medication is auto-refilled
                 * 
                 * @param int $medication_id
                 * @param int $new_refills_left
                 */
                do_action('pillpalnow/auto_refilled', $medication_id, $refills_left);
            }
        }
    }
}

// Initialize
new PillPalNow_Refill_Manager();
