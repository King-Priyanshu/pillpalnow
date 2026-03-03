<?php
/**
 * Renewal Reminder Email (Plain Text)
 *
 * @package PillPalNow
 */

echo "Subscription Renewal Reminder\n\n";
echo "Hi " . $user_name . ",\n\n";
echo "Your PillPalNow subscription will renew soon.\n\n";
if (!empty($plan_name)) {
    echo "Plan: " . $plan_name . "\n";
}
if (!empty($renewal_date)) {
    echo "Renewal date: " . $renewal_date . "\n";
}
if (!empty($amount)) {
    echo "Amount: " . $amount . "\n";
}
echo "\n";
echo "You can manage your subscription at: " . $manage_url . "\n\n";
echo "Thanks,\n";
echo "The PillPalNow Team\n";
echo $site_url . "\n";
?>