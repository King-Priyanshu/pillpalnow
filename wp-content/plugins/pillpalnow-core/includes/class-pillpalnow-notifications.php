<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Notifications Manager
 * 
 * Handles creation, retrieval, and management of in-app notifications
 * for medication-related events.
 * 
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Notifications
{
    /**
     * Notification Types
     */
    const TYPE_ASSIGNED = 'assigned';
    const TYPE_REMINDER = 'reminder';
    const TYPE_TAKEN = 'taken';
    const TYPE_SKIPPED = 'skipped';
    const TYPE_MISSED = 'missed';
    const TYPE_POSTPONED = 'postponed';
    const TYPE_REFILL_LOW = 'refill_low';
    const TYPE_FAMILY_LOGIN = 'family_login';
    const TYPE_MAGIC_LINK_SENT = 'magic_link_sent';
    const TYPE_SYSTEM_ALERT = 'system_alert';
    const TYPE_SUBSCRIPTION = 'subscription';
    const TYPE_BILLING = 'billing';

    /**
     * Notification Status
     */
    const STATUS_UNREAD = 'unread';
    const STATUS_READ = 'read';
    const STATUS_DELETED = 'deleted';

    /**
     * Initialize the notifications system
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_notification_cpt'));
    }

    /**
     * Register Notification Custom Post Type
     */
    public static function register_notification_cpt()
    {
        $labels = array(
            'name' => _x('Notifications', 'Post Type General Name', 'pillpalnow'),
            'singular_name' => _x('Notification', 'Post Type Singular Name', 'pillpalnow'),
            'menu_name' => __('Notifications', 'pillpalnow'),
        );

        $args = array(
            'label' => __('Notification', 'pillpalnow'),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'custom-fields', 'author'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'menu_icon' => 'dashicons-bell',
            'capability_type' => 'post',
            'has_archive' => false,
            'show_in_rest' => false,
        );

        register_post_type('notification', $args);
    }

    /**
     * Create a new notification
     * 
     * @param int $user_id User ID to notify
     * @param string $type Notification type (use class constants)
     * @param string $title Notification title
     * @param string $message Notification message
     * @param int|null $medication_id Related medication ID (optional)
     * @param string|null $related_url URL to navigate on click (optional)
     * @return int|false Post ID on success, false on failure
     */
    public static function create($user_id, $type, $title, $message, $medication_id = null, $related_url = null)
    {
        // Validate user
        if (!$user_id || !get_userdata($user_id)) {
            return false;
        }

        // Permission Check: Ensure user is allowed to receive notifications
        if (class_exists('PillPalNow_Permissions') && !PillPalNow_Permissions::can_user($user_id, PillPalNow_Permissions::CAN_RECEIVE_NOTIFICATIONS)) {
            return false; // Silently fail if permission denied
        }

        // Deduplication check for in-app notifications
        $content = $user_id . '|' . $type . '|' . $title . '|' . $message . '|' . $medication_id . '|' . $related_url;
        $hash = md5($content);
        $transient_key = 'pillpalnow_inapp_' . $hash;
        if (get_transient($transient_key)) {
            return false;
        }

        // Create notification post
        $post_id = wp_insert_post(array(
            'post_title' => sanitize_text_field($title),
            'post_content' => sanitize_textarea_field($message),
            'post_type' => 'notification',
            'post_status' => 'publish',
            'post_author' => $user_id,
        ));

        if (!$post_id || is_wp_error($post_id)) {
            return false;
        }

        // Add metadata
        update_post_meta($post_id, 'user_id', $user_id);
        update_post_meta($post_id, 'type', sanitize_text_field($type));
        update_post_meta($post_id, 'status', self::STATUS_UNREAD);

        if ($medication_id) {
            update_post_meta($post_id, 'medication_id', intval($medication_id));
        }

        if ($related_url) {
            update_post_meta($post_id, 'related_url', esc_url_raw($related_url));
        }

        // Set deduplication transient
        set_transient($transient_key, 1, 3600); // 1 hour

        return $post_id;
    }

    /**
     * Get notifications for a user
     * 
     * @param int $user_id User ID
     * @param array $args Query arguments
     * @return array Array of notification objects
     */
    public static function get_notifications($user_id, $args = array())
    {
        $defaults = array(
            'limit' => 20,
            'status' => 'all', // 'unread', 'read', 'all'
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $query_args = array(
            'post_type' => 'notification',
            'author' => $user_id,
            'posts_per_page' => $args['limit'],
            'offset' => $args['offset'],
            'orderby' => 'date',
            'order' => 'DESC',
        );

        // Filter by status
        // If 'all', we exclude 'deleted'.
        // If specific status, we use that.
        if ($args["status"] !== "all") {
            $query_args["meta_query"] = array(
                "relation" => "AND",
                array(
                    "key" => "status",
                    "value" => $args["status"],
                ),
                array(
                    "key" => "status",
                    "value" => self::STATUS_DELETED,
                    "compare" => "!=",
                ),
            );
        } else {
            // Default: Exclude deleted
            $query_args["meta_query"] = array(
                array(
                    "key" => "status",
                    "value" => self::STATUS_DELETED,
                    "compare" => "!=",
                ),
            );
        }

        $posts = get_posts($query_args);
        $notifications = array();

        foreach ($posts as $post) {
            // Double check safe-guard against deleted if meta query fails creatively
            $s = get_post_meta($post->ID, 'status', true);
            if ($s === self::STATUS_DELETED)
                continue;

            $notifications[] = self::format_notification($post);
        }

        return $notifications;
    }

    /**
     * Get total count of notifications for a user
     *
     * @param int $user_id User ID
     * @param string $status Status filter ('all', 'unread', 'read')
     * @return int Total count
     */
    public static function get_total_count($user_id, $status = 'all')
    {
        $query_args = array(
            'post_type' => 'notification',
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        if ($status !== 'all') {
            $query_args['meta_query'] = array(
                array(
                    'key' => 'status',
                    'value' => $status,
                ),
            );
        } else {
            $query_args['meta_query'] = array(
                array(
                    'key' => 'status',
                    'value' => self::STATUS_DELETED,
                    'compare' => '!=',
                ),
            );
        }

        $posts = get_posts($query_args);
        return count($posts);
    }

    /**
     * Format notification post into structured array
     * 
     * @param WP_Post $post Notification post object
     * @return array Formatted notification data
     */
    private static function format_notification($post)
    {
        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'message' => $post->post_content,
            'type' => get_post_meta($post->ID, 'type', true),
            'status' => get_post_meta($post->ID, 'status', true),
            'medication_id' => get_post_meta($post->ID, 'medication_id', true),
            'related_url' => get_post_meta($post->ID, 'related_url', true),
            'created_at' => $post->post_date,
            'created_timestamp' => strtotime($post->post_date),
        );
    }

    /**
     * Get unread notification count for a user
     * 
     * @param int $user_id User ID
     * @return int Unread count
     */
    public static function get_unread_count($user_id)
    {
        $count = count(get_posts(array(
            'post_type' => 'notification',
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => 'status',
                    'value' => self::STATUS_UNREAD,
                ),
            ),
        )));

        return $count;
    }

    /**
     * Mark notifications as read
     * 
     * @param array $notification_ids Array of notification IDs
     * @param int $user_id User ID (for security check)
     * @return int Number of notifications updated
     */
    public static function mark_as_read($notification_ids, $user_id)
    {
        if (!is_array($notification_ids)) {
            $notification_ids = array($notification_ids);
        }

        $updated = 0;

        foreach ($notification_ids as $id) {
            $post = get_post($id);

            // Security check: ensure notification belongs to user
            if (!$post || $post->post_type !== 'notification' || (int) $post->post_author !== (int) $user_id) {
                continue;
            }

            update_post_meta($id, 'status', self::STATUS_READ);
            $updated++;
        }

        return $updated;
    }

    /**
     * Soft delete notifications
     * 
     * @param array $notification_ids Array of notification IDs
     * @param int $user_id User ID
     * @return int Number of notifications deleted
     */
    public static function soft_delete($notification_ids, $user_id)
    {
        if (!is_array($notification_ids)) {
            $notification_ids = array($notification_ids);
        }

        $deleted = 0;

        foreach ($notification_ids as $id) {
            $post = get_post($id);

            // Security check
            if (!$post || $post->post_type !== 'notification' || (int) $post->post_author !== (int) $user_id) {
                continue;
            }

            update_post_meta($id, 'status', self::STATUS_DELETED);
            update_post_meta($id, 'deleted_at', current_time('mysql'));
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Delete old read notifications (cleanup)
     * 
     * @param int $days_old Delete read notifications older than this many days
     * @return int Number of notifications deleted
     */
    public static function cleanup_old_notifications($days_old = 30)
    {
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        $old_notifications = get_posts(array(
            'post_type' => 'notification',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'before' => $cutoff_date,
                    'inclusive' => false,
                ),
            ),
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'status',
                    'value' => self::STATUS_READ,
                ),
                array(
                    'key' => 'status',
                    'value' => self::STATUS_DELETED,
                )
            ),
        ));

        $deleted = 0;

        foreach ($old_notifications as $notif) {
            if (wp_delete_post($notif->ID, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Remove postponed notifications for a medication
     * Ensures only one active postponed notification exists
     * 
     * @param int $medication_id Medication ID
     * @param int $user_id User ID
     * @param int|null $exclude_id Notification ID to exclude (optional)
     * @return int Number of notifications removed
     */
    public static function remove_postponed_notifications($medication_id, $user_id, $exclude_id = null)
    {
        $query_args = array(
            'post_type' => 'notification',
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'medication_id',
                    'value' => $medication_id,
                ),
                array(
                    'key' => 'type',
                    'value' => self::TYPE_POSTPONED,
                ),
                array(
                    'key' => 'status',
                    'value' => self::STATUS_UNREAD,
                ),
            ),
        );

        if ($exclude_id) {
            $query_args['post__not_in'] = array($exclude_id);
        }

        $old_notifications = get_posts($query_args);
        $deleted = 0;

        foreach ($old_notifications as $notif_id) {
            if (wp_delete_post($notif_id, true)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Mark reminder notification as read when dose is taken/skipped
     * 
     * @param int $medication_id Medication ID
     * @param int $user_id User ID
     * @param string $date Log date (Y-m-d format)
     * @return int Number of notifications marked as read
     */
    public static function close_reminder_notification($medication_id, $user_id, $date)
    {
        $notifications = get_posts(array(
            'post_type' => 'notification',
            'author' => $user_id,
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'medication_id',
                    'value' => $medication_id,
                ),
                array(
                    'key' => 'type',
                    'value' => self::TYPE_REMINDER,
                ),
                array(
                    'key' => 'status',
                    'value' => self::STATUS_UNREAD,
                ),
            ),
        ));

        $updated = 0;

        foreach ($notifications as $notif) {
            // Check if notification is from the same day
            $notif_date = date('Y-m-d', strtotime($notif->post_date));
            if ($notif_date === $date) {
                update_post_meta($notif->ID, 'status', self::STATUS_READ);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Check if a missed notification already exists
     * Prevents duplicate missed notifications
     * 
     * @param int $medication_id Medication ID
     * @param int $user_id User ID
     * @param string $date Date to check (Y-m-d format)
     * @return bool True if missed notification exists, false otherwise
     */
    public static function has_missed_notification($medication_id, $user_id, $date)
    {
        $existing = get_posts(array(
            'post_type' => 'notification',
            'author' => $user_id,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'date_query' => array(
                array(
                    'year' => date('Y', strtotime($date)),
                    'month' => date('m', strtotime($date)),
                    'day' => date('d', strtotime($date)),
                ),
            ),
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'medication_id',
                    'value' => $medication_id,
                ),
                array(
                    'key' => 'type',
                    'value' => self::TYPE_MISSED,
                ),
            ),
        ));

        return !empty($existing);
    }

    /**
     * Clear refill low notifications for a medication
     * Called when medication is refilled to remove stale alerts
     * 
     * @param int $medication_id Medication ID
     * @param int $user_id User ID
     * @return int Number of notifications removed
     */
    public static function clear_refill_notifications($medication_id, $user_id)
    {
        $notifications = get_posts(array(
            'post_type' => 'notification',
            'author' => $user_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'medication_id',
                    'value' => $medication_id,
                ),
                array(
                    'key' => 'type',
                    'value' => self::TYPE_REFILL_LOW,
                ),
            ),
        ));

        $deleted = 0;

        foreach ($notifications as $notif_id) {
            // Soft delete (mark as deleted) rather than hard delete
            update_post_meta($notif_id, 'status', self::STATUS_DELETED);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Get user notification preferences
     *
     * @param int $user_id User ID
     * @return array Preferences array
     */
    public static function get_preferences($user_id)
    {
        $defaults = array(
            'reminders' => true,
            'refills' => true,
            'missed' => true,
            'family' => true,
        );

        $preferences = get_user_meta($user_id, 'notification_preferences', true);

        if (!is_array($preferences)) {
            $preferences = array();
        }

        return array_merge($defaults, $preferences);
    }

    /**
     * Update user notification preferences
     *
     * @param int $user_id User ID
     * @param array $preferences Preferences array
     * @return bool Success
     */
    public static function update_preferences($user_id, $preferences)
    {
        $valid_keys = array('reminders', 'refills', 'missed', 'family');

        $cleaned = array();
        foreach ($valid_keys as $key) {
            $cleaned[$key] = isset($preferences[$key]) && $preferences[$key] ? true : false;
        }

        return update_user_meta($user_id, 'notification_preferences', $cleaned);
    }
}

// Initialize the notifications system
PillPalNow_Notifications::init();
