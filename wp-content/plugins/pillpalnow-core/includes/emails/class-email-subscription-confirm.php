<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Subscription Confirmation Email
 * 
 * Sent when a new subscription is created/activated.
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Subscription_Confirm extends PillPalNow_Email_Base
{
    public function __construct()
    {
        $this->id = 'pillpalnow_subscription_confirm';
        $this->title = __('Subscription Confirmation', 'pillpalnow');
        $this->description = __('Sent when a user subscribes to a PillPalNow plan.', 'pillpalnow');
        $this->context_type = 'subscription';

        parent::__construct();
    }

    protected function get_template_filename()
    {
        return 'subscription-confirmation.php';
    }

    public function get_default_subject()
    {
        return __('Welcome to {site_title} Pro! 🎉', 'pillpalnow');
    }

    public function get_default_heading()
    {
        return __('Your Subscription is Active', 'pillpalnow');
    }
}
