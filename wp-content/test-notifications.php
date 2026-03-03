<?php
require_once dirname(__DIR__) . '/wp-load.php';
$user_id = 1;
wp_set_current_user($user_id);
echo "Unread count: " . PillPalNow_Notifications::get_unread_count($user_id) . "\n";
$notifs = PillPalNow_Notifications::get_notifications($user_id, array('status' => 'unread'));
echo "Notifications count: " . count($notifs) . "\n";
print_r($notifs);
