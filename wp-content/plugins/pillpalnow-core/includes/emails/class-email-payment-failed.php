<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payment Failed Email
 * 
 * Sent when a subscription payment fails.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Payment_Failed extends PillPalNow_Email_Base
{
    public function __construct()
    {
        $this->id = 'pillpalnow_payment_failed';
        $this->title = __('Payment Failed', 'pillpalnow');
        $this->description = __('Sent when a subscription payment attempt fails.', 'pillpalnow');
        $this->context_type = 'billing';

        parent::__construct();
    }

    protected function get_template_filename()
    {
        return 'payment-failed.php';
    }

    public function get_default_subject()
    {
        return __('⚠️ Action Required: Payment Failed — {site_title}', 'pillpalnow');
    }

    public function get_default_heading()
    {
        return __('Payment Could Not Be Processed', 'pillpalnow');
    }
}
