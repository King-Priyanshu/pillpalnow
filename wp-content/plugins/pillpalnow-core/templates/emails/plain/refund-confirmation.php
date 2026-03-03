<?php
/**
 * Refund Confirmation Email (Plain Text)
 *
 * @package PillPalNow
 */

echo "Refund Processed\n\n";
echo "Hi " . $user_name . ",\n\n";
echo "Your refund has been processed successfully.\n\n";
if (!empty($amount)) {
    echo "Refund amount: " . $amount . "\n";
}
if (!empty($refund_date)) {
    echo "Refund date: " . $refund_date . "\n";
}
if (!empty($reason)) {
    echo "Reason: " . $reason . "\n";
}
echo "\n";
echo "If you have any questions about this refund, please contact support.\n\n";
echo "Thanks,\n";
echo "The PillPalNow Team\n";
echo $site_url . "\n";
?>