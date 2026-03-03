<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cancellation Confirmation Email
 * 
 * Sent when a subscription is cancelled.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Cancellation_Confirm extends PillPalNow_Email_Base
{
    public function __construct()
    {
        $this->id = 'pillpalnow_cancellation_confirm';
        $this->title = __('Cancellation Confirmation', 'pillpalnow');
        $this->description = __('Sent when a user cancels their subscription.', 'pillpalnow');
        $this->context_type = 'subscription';

        parent::__construct();
    }

    protected function get_template_filename()
    {
        return 'cancellation-confirmation.php';
    }

    public function get_default_subject()
    {
        return __('Your {site_title} subscription has been cancelled', 'pillpalnow');
    }

    public function get_default_heading()
    {
        return __('Subscription Cancelled', 'pillpalnow');
    }
}
