<?php
/**
 * Cancellation Confirmation Email (Plain Text)
 *
 * @package PillPalNow
 */

echo "Subscription Cancelled\n\n";
echo "Hi " . $user_name . ",\n\n";
echo "Your PillPalNow subscription has been cancelled.\n\n";
if (!empty($plan_name)) {
    echo "Plan: " . $plan_name . "\n";
}
if (!empty($end_date)) {
    echo "Access until: " . $end_date . "\n";
}
echo "\n";
echo "We're sorry to see you go! If you change your mind, you can reactivate your subscription at any time.\n\n";
echo "Thanks,\n";
echo "The PillPalNow Team\n";
echo $site_url . "\n";
?>