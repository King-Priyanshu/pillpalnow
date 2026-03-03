<?php
/**
 * Payment Failed Email (Plain Text)
 *
 * @package PillPalNow
 */

echo "Action Required: Payment Failed\n\n";
echo "Hi " . $user_name . ",\n\n";
echo "We were unable to process your payment for your PillPalNow subscription.\n\n";
echo "Payment details:\n";
if (!empty($amount)) {
    echo "- Amount: $" . $amount . " " . $currency . "\n";
}
echo "\n";
echo "Please update your payment method to avoid losing access to Pro features.\n";
echo "You can manage your payment methods at: " . $manage_url . "\n\n";
echo "Thanks,\n";
echo "The PillPalNow Team\n";
echo $site_url . "\n";
?>