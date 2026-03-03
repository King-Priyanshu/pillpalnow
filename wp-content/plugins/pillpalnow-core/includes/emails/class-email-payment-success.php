<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment Success Email
 * 
 * Sent when an invoice is paid (subscription renewal or one-time payment).
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Payment_Success extends PillPalNow_Email_Base
{
    public function __construct()
    {
        $this->id = 'pillpalnow_payment_success';
        $this->title = __('Payment Success', 'pillpalnow');
        $this->description = __('Sent when a subscription payment is successfully processed.', 'pillpalnow');
        $this->context_type = 'billing';

        parent::__construct();
    }

    protected function get_template_filename()
    {
        return 'payment-success.php';
    }

    public function get_default_subject()
    {
        return __('Payment Received — {site_title}', 'pillpalnow');
    }

    public function get_default_heading()
    {
        return __('Payment Successful', 'pillpalnow');
    }
}
