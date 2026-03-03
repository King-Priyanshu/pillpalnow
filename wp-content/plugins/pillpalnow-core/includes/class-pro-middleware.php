<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Pro_Middleware
 * Protects API endpoints by verifying Pro subscription status
 */
class Pro_Middleware
{
    /**
     * Check if current user has active Pro subscription
     * 
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function check_pro_access($request)
    {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return new WP_Error('rest_forbidden', __('You must be logged in to access this resource.', 'pillpalnow'), array('status' => 401));
        }

        if (class_exists('Subscription_Manager') && !Subscription_Manager::is_pro_user($user_id)) {
            return new WP_Error('rest_forbidden_pro', __('This feature requires a Pro subscription.', 'pillpalnow'), array('status' => 403));
        }

        return true;
    }
}
