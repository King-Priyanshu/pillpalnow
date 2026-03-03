<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper class to test notification delivery
 */
class PillPalNow_Notification_Tester
{
    /**
     * Send a test notification
     *
     * @param array $args
     * @return array
     */
    public static function send_test_notification($args)
    {
        $provider = isset($args['provider']) ? $args['provider'] : 'onesignal';
        $recipient = isset($args['recipient']) ? $args['recipient'] : '';
        $heading = isset($args['heading']) ? $args['heading'] : 'Test Notification';
        $message = isset($args['message']) ? $args['message'] : 'Test Message';

        $user_id = get_current_user_id();

        // If recipient allows specifying user ID, handle it
        if (is_numeric($recipient) && $recipient > 0) {
            $user_id = intval($recipient);
        }

        if ($provider === 'onesignal') {
            if (class_exists('PillPalNow_OneSignal_Service')) {
                $service = PillPalNow_OneSignal_Service::get_instance();
                $result = $service->send_notification($user_id, $heading, $message, 'test', 'normal');
                if ($result) {
                    return array('success' => true, 'message' => 'Test notification sent via OneSignal.');
                } else {
                    return array('success' => false, 'message' => 'OneSignal send failed. Check logs.');
                }
            } else {
                return array('success' => false, 'message' => 'OneSignal service not available.');
            }
        } elseif ($provider === 'email_fallback') {
            // Force email fallback
            if (function_exists('pillpalnow_send_email_fallback')) {
                pillpalnow_send_email_fallback($user_id, $heading, $message, 'test', 'Manual Test');
                return array('success' => true, 'message' => 'Fallback email triggered.');
            }
        }

        return array('success' => false, 'message' => 'Invalid provider or unimplemented.');
    }
}
