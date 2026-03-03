<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PillPalNow Action Logger
 *
 * Handles logging of user actions (Add/Edit/Delete, etc.) for audit trails.
 *
 * @package PillPalNow
 * @since 1.0.0
 */
class PillPalNow_Action_Logger
{
    private static $table_name = 'pillpalnow_action_logs';

    public static function init()
    {
        add_action('plugins_loaded', array(__CLASS__, 'create_table'));
        add_action('pillpalnow_daily_cleanup', array(__CLASS__, 'cleanup_old_logs'));
    }

    public static function create_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table exists to avoid running dbDelta on every load (though dbDelta handles it)
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                action_type varchar(50) NOT NULL,
                resource_id bigint(20) DEFAULT 0,
                resource_type varchar(50) DEFAULT '',
                details text,
                source varchar(20) DEFAULT 'manual',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY action_type (action_type),
                KEY resource_id (resource_id),
                KEY created_at (created_at)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    /**
     * Log an action
     *
     * @param int $user_id User performing the action
     * @param string $action_type e.g., 'add_medication', 'delete_medication'
     * @param int $resource_id ID of the object being acted upon
     * @param string $resource_type e.g., 'medication', 'refill', 'user'
     * @param string|array $details Additional details or JSON
     * @param string $source 'manual', 'api', 'auto'
     */
    public static function log($user_id, $action_type, $resource_id = 0, $resource_type = '', $details = '', $source = 'manual')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        if (is_array($details)) {
            $details = json_encode($details);
        }

        $data = array(
            'user_id' => $user_id,
            'action_type' => $action_type,
            'resource_id' => $resource_id,
            'resource_type' => $resource_type,
            'details' => $details,
            'source' => $source,
            'created_at' => current_time('mysql'),
        );

        return $wpdb->insert($table_name, $data);
    }

    public static function cleanup_old_logs($days_old = 90)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE created_at < %s", $cutoff_date));
    }
}

// Initialize
PillPalNow_Action_Logger::init();
