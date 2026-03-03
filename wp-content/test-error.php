<?php
// Catch fatal errors and print them
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        echo "FATAL ERROR CAUGHT: ";
        print_r($error);
    }
});
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load WP
require_once dirname(__FILE__) . '/../wp-load.php';

echo "WordPress Loaded successfully.\n";

// Emulate admin-post.php for pillpalnow_add_medication
$_POST['action'] = 'pillpalnow_add_medication';
$_POST['post_title'] = 'Test Med Curl AdminPost natively';
$_POST['schedule_type'] = 'daily';
$_POST['dose_time'] = ['08:00'];
$_POST['dose_amount'] = ['1'];

echo "Executing action...\n";
do_action('admin_post_pillpalnow_add_medication');
echo "Action completed.\n";
