<?php
require_once dirname(__DIR__) . '/wp-load.php';
$post_data = array(
    'post_title' => 'Test Family Member',
    'post_type' => 'family_member',
    'post_status' => 'publish',
    'post_author' => 1,
    'meta_input' => array('relation' => 'Test', 'relation_type' => 'Test')
);
$result = wp_insert_post($post_data);
if (is_wp_error($result)) {
    echo "ERROR: " . $result->get_error_message() . "\n";
} else {
    echo "SUCCESS: Created family member with ID $result\n";
    wp_delete_post($result, true);
}
