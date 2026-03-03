<?php
/**
 * Payment Success Email (Plain Text)
 *
 * @package PillPalNow
 */

echo "Payment Successful!\n\n";
echo "Hi " . $user_name . ",\n\n";
echo "Your payment has been processed successfully.\n\n";
echo "Payment details:\n";
if (!empty($amount)) {
    echo "- Amount: $" . $amount . " " . $currency . "\n";
}
if (!empty($invoice_id)) {
    echo "- Invoice ID: " . $invoice_id . "\n";
}
if (!empty($invoice_url)) {
    echo "- Invoice: " . $invoice_url . "\n";
}
if (!empty($next_billing_date)) {
    echo "- Next billing date: " . $next_billing_date . "\n";
}
echo "\n";
echo "Thanks,\n";
echo "The PillPalNow Team\n";
echo $site_url . "\n";
?>