<?php
require_once '/home/priyanshu/Downloads/apps/wp-load.php';

$user_id = 1;
wp_set_current_user($user_id);
echo "User ID: " . get_current_user_id() . "\n";

echo "Unread count: " . PillPalNow_Notifications::get_unread_count($user_id) . "\n";

$args = array('status' => 'unread', 'limit' => 20, 'offset' => 0);
$notifs = PillPalNow_Notifications::get_notifications($user_id, $args);

echo "Notifications count from get_notifications: " . count($notifs) . "\n";
if (empty($notifs)) {
    echo "Query args used:\n";
    $query_args = array(
        'post_type' => 'notification',
        'author' => $user_id,
        'posts_per_page' => $args['limit'],
        'offset' => $args['offset'],
        'orderby' => 'date',
        'order' => 'DESC',
    );
    if ($args['status'] !== 'all') {
        $query_args['meta_query'] = array(
            array(
                'key' => 'status',
                'value' => $args['status'],
            ),
        );
    }
    
    // Let's see what get_posts actually finds
    $posts = get_posts($query_args);
    echo "Raw get_posts count: " . count($posts) . "\n";
    
    // Remove offset and test again
    unset($query_args['offset']);
    $posts_no_offset = get_posts($query_args);
    echo "Raw get_posts count without offset: " . count($posts_no_offset) . "\n";
    
    // Check if there are any posts for the user at all
    $all = get_posts(array('post_type' => 'notification', 'author' => $user_id, 'posts_per_page' => -1));
    echo "Total notification posts for user: " . count($all) . "\n";
}
