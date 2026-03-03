<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Refund Confirmation Email
 * 
 * Sent when a charge is refunded.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Refund_Confirm extends PillPalNow_Email_Base
{
    public function __construct()
    {
        $this->id = 'pillpalnow_refund_confirm';
        $this->title = __('Refund Confirmation', 'pillpalnow');
        $this->description = __('Sent when a refund is processed for a user.', 'pillpalnow');
        $this->context_type = 'billing';

        parent::__construct();
    }

    protected function get_template_filename()
    {
        return 'refund-confirmation.php';
    }

    public function get_default_subject()
    {
        return __('Refund Processed — {site_title}', 'pillpalnow');
    }

    public function get_default_heading()
    {
        return __('Your Refund Has Been Processed', 'pillpalnow');
    }
}
