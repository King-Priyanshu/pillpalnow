<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Permissions Handler
 * 
 * Centralizes all permission logic for Family Members vs Parents/Admins.
 * 
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Permissions
{

    /**
     * Permission Keys mapping to Settings
     */
    const CAN_ADD_MEDICATION = 'pillpalnow_allow_add';
    const CAN_EDIT_MEDICATION = 'pillpalnow_allow_edit';
    const CAN_DELETE_MEDICATION = 'pillpalnow_allow_delete';
    const CAN_VIEW_HISTORY = 'pillpalnow_allow_history';
    const CAN_VIEW_REFILLS = 'pillpalnow_allow_refill_logs';
    const CAN_RECEIVE_NOTIFICATIONS = 'pillpalnow_allow_notifications';

    /**
     * Check if a user has permission to perform a specific action
     * 
     * @param int $user_id The user ID to check (defaults to current user)
     * @param string $permission The permission settings key (use class constants)
     * @return bool True if allowed, False if blocked
     */
    public static function can_user($user_id = null, $permission = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        // 1. Admins Bypass Check
        if (user_can($user_id, 'administrator')) {
            return true;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // 2. Distinguish Primary User vs Family Member via Meta & Role
        // Logic: Family Members are defined by having a Linked Parent.
        // We check both the new key 'pillpalnow_parent_user' and legacy 'parent_user_id'.
        $parent_user_id = (int) get_user_meta($user_id, 'pillpalnow_parent_user', true);
        $legacy_parent_id = (int) get_user_meta($user_id, 'parent_user_id', true);

        // DEBUG LOGGING (TEMPORARY)
        $debug_msg = sprintf(
            "[PILLPALNOW_DEBUG] User: %d | ParentMeta: %d | LegacyMeta: %d | Roles: %s | Checking: %s",
            $user_id,
            $parent_user_id,
            $legacy_parent_id,
            isset($user->roles) ? implode(',', (array) $user->roles) : 'none',
            $permission
        );
        error_log($debug_msg);

        // If NO parent is linked, they are a Primary User (Free or Pro).
        // We explicitly ALLOW Primary Users to manage their own data.
        // We ignore the 'family_member' role check here to ensure self-registered users are not blocked 
        // if they accidentally have the role but no parent.
        if ($parent_user_id <= 0 && $legacy_parent_id <= 0) {
            error_log("[PILLPALNOW_DEBUG] -> RESULT: ALLOWED (Primary User)");
            return true;
        }

        // 3. Family Member Logic - Strict Permission Check
        // Default to FALSE (Restricted behavior)
        $allowed = get_user_meta($user_id, $permission, true);

        // DEBUG LOGGING
        // error_log("PillPalNow Permission Check: User ID {$user_id}, Permission {$permission}, Result: " . ($allowed ? 'ALLOWED' : 'DENIED'));

        return (bool) $allowed;
    }

    /**
     * Helper to check current user against a permission and die/return error if failed.
     * 
     * @param string $permission
     * @param bool $return_bool If true, returns bool instead of wp_die/Exception
     * @return bool|void
     */
    public static function check($permission, $return_bool = false)
    {
        $allowed = self::can_user(null, $permission);

        if ($return_bool) {
            return $allowed;
        }

        if (!$allowed) {
            if (defined('DOING_AJAX') && DOING_AJAX) {
                wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
            } else {
                wp_die('You do not have permission to perform this action.', 'Permission Denied', array('response' => 403));
            }
        }

        return true;
    }
}
