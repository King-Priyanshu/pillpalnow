<?php
/**
 * Subscription Confirmation Email (Plain Text)
 *
 * @package PillPalNow
 */

echo "Welcome to PillPalNow Pro!\n\n";
echo "Hi " . $user_name . ",\n\n";
echo "Thank you for subscribing to " . $plan_name . ". You now have access to all Pro features.\n\n";
echo "Your subscription details:\n";
echo "- Plan: " . $plan_name . "\n";
if (!empty($amount)) {
    echo "- Amount: " . $amount . "\n";
}
if (!empty($next_billing_date)) {
    echo "- Next billing date: " . $next_billing_date . "\n";
}
echo "\n";
echo "You can manage your subscription at: " . $manage_url . "\n\n";
echo "Thanks,\n";
echo "The PillPalNow Team\n";
echo $site_url . "\n";
?>