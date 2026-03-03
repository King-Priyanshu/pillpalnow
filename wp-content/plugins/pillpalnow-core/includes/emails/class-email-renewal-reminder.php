<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renewal Reminder Email
 * 
 * Sent a few days before a subscription renews (via cron).
 * 
 * @package PillPalNow
 * @since 2.0.0
 */
class PillPalNow_Email_Renewal_Reminder extends PillPalNow_Email_Base
{
    public function __construct()
    {
        $this->id = 'pillpalnow_renewal_reminder';
        $this->title = __('Subscription Renewal Reminder', 'pillpalnow');
        $this->description = __('Sent before a subscription automatically renews.', 'pillpalnow');
        $this->context_type = 'subscription';

        parent::__construct();
    }

    protected function get_template_filename()
    {
        return 'renewal-reminder.php';
    }

    public function get_default_subject()
    {
        return __('Your {site_title} subscription renews soon', 'pillpalnow');
    }

    public function get_default_heading()
    {
        return __('Renewal Reminder', 'pillpalnow');
    }
}
