<?php
/**
 * Admin Notification Email (Plain Text)
 *
 * @package PillPalNow
 */

echo $site_name . " Subscription Event Notification\n\n";
echo "An important subscription event has occurred:\n\n";
echo "Event: " . $event_description . "\n";
echo "Notification Type: " . $notification_type . "\n";
echo "Affected User: " . $affected_user_name . " (ID: " . $affected_user_id . ")\n";
if (!empty($affected_user_email)) {
    echo "User Email: " . $affected_user_email . "\n";
}
echo "\n";
echo "You can view more details in the admin dashboard: " . $manage_url . "\n\n";
echo "Thanks,\n";
echo "PillPalNow System\n";
?>