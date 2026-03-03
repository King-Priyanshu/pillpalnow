<?php
/**
 * Create Notification Logs Table
 */
require_once('wp-load.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Creating Notification Logs Table</h1>";

global $wpdb;
$table_name = $wpdb->prefix . 'notification_logs';

// Check if table exists
if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
    echo "<p style='color: green'>Table already exists</p>";
} else {
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        notification_type varchar(100) NOT NULL,
        notification_channel varchar(50) NOT NULL,
        status varchar(50) NOT NULL,
        message text NOT NULL,
        meta text,
        log_time datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY notification_type (notification_type),
        KEY status (status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        echo "<p style='color: green'>Table created successfully</p>";
    } else {
        echo "<p style='color: red'>Failed to create table</p>";
        echo "<p>SQL Error: " . $wpdb->last_error . "</p>";
    }
}

// Check if we can insert a test log
echo "<h2>Inserting Test Log</h2>";
$test_data = array(
    'user_id' => 1,
    'notification_type' => 'debug',
    'notification_channel' => 'onesignal',
    'status' => 'sent',
    'message' => 'Test notification log entry',
    'meta' => json_encode(array('test' => true, 'timestamp' => time()))
);

$wpdb->insert($table_name, $test_data);

if ($wpdb->insert_id) {
    echo "<p style='color: green'>Test log inserted successfully (ID: " . $wpdb->insert_id . ")</p>";
    
    // Verify insertion
    $test_log = $wpdb->get_row("SELECT * FROM $table_name WHERE id = " . $wpdb->insert_id, ARRAY_A);
    echo "<h3>Test Log Details</h3>";
    echo "<pre>" . print_r($test_log, true) . "</pre>";
} else {
    echo "<p style='color: red'>Failed to insert test log</p>";
    echo "<p>MySQL Error: " . $wpdb->last_error . "</p>";
}
?>
