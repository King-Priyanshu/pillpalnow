<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        echo "FATAL ERROR CAUGHT: ";
        print_r($error);
    }
});
$_POST['pillpalnow_add_med_nonce'] = wp_create_nonce('pillpalnow_add_med_action');
$_POST['post_title'] = 'Test Med Do Action';
$_POST['schedule_type'] = 'daily';
$_POST['dose_time'] = ['08:00'];
$_POST['dose_amount'] = ['1'];
$_REQUEST['action'] = 'pillpalnow_add_medication';
wp_set_current_user(1);

do_action('admin_post_pillpalnow_add_medication');
echo "SUCCESS";
