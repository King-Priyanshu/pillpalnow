<?php
// Load WordPress
require_once('/Users/bot/development/upwork_project/wordpress/wp-load.php');

$args = array(
    'post_type' => 'family_member',
    'posts_per_page' => 5,
    'orderby' => 'date',
    'order' => 'DESC'
);
$members = get_posts($args);

echo "Found " . count($members) . " members.\n";

foreach ($members as $m) {
    echo "ID: " . $m->ID . " | Title: " . $m->post_title . "\n";
    echo "  Relation: '" . get_post_meta($m->ID, 'relation', true) . "'\n";
    echo "  Email: '" . get_post_meta($m->ID, 'email', true) . "'\n";
    echo "---------------------------\n";
}
