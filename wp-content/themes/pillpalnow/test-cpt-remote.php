<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
$post_data = array(
    'post_title' => 'Test Item',
    'post_type' => 'family_member',
    'post_status' => 'publish',
    'post_author' => 1
);
$id = wp_insert_post($post_data);
if (is_wp_error($id)) {
    echo "ERROR: " . $id->get_error_message() . "\n";
} else {
    echo "SUCCESS: Created family_member with ID $id\n";
    wp_delete_post($id, true);
}
