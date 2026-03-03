<?php
// Load WordPress
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');

if (!current_user_can('manage_options') && !is_user_logged_in()) {
    // Basic protection, though standard user might need to see this to debug their view
    // Let's allow logged in users.
    // die('Access Denied');
}

echo "<h1>Medication Debug Dump</h1>";

// 1. Dump All Meds (Any Status)
$all_meds = get_posts(array(
    'post_type' => 'medication',
    'post_status' => 'any',
    'posts_per_page' => -1
));

echo "<h2>All Legacy Medications (DB Dump)</h2>";
echo "<table border='1' cellpadding='5'>
<tr>
    <th>ID</th>
    <th>Title</th>
    <th>Status</th>
    <th>Author</th>
    <th>Assigned User (Meta)</th>
    <th>Family Member (Meta)</th>
</tr>";

foreach ($all_meds as $med) {
    $assigned_user_id = get_post_meta($med->ID, 'assigned_user_id', true);
    $family_member_id = get_post_meta($med->ID, 'family_member_id', true);

    echo "<tr>";
    echo "<td>" . $med->ID . "</td>";
    echo "<td>" . $med->post_title . "</td>";
    echo "<td>" . $med->post_status . "</td>";
    echo "<td>" . $med->post_author . "</td>";
    echo "<td>" . $assigned_user_id . "</td>";
    echo "<td>" . $family_member_id . "</td>";
    echo "</tr>";
}
echo "</table>";

// 2. Dump What Refills Page Would See (Simulated)
$current_user_id = get_current_user_id();
echo "<h2>Refills Page Simulated Query for User ID: $current_user_id</h2>";

// Logic from page-refills.php
$refill_meds = get_posts(array(
    'post_type' => 'medication',
    'posts_per_page' => -1,
    'post_status' => 'publish', // Added by my fix
    'meta_query' => array(
        'relation' => 'OR',
        array('key' => 'assigned_user_id', 'value' => $current_user_id),
        array('key' => 'assigned_to', 'value' => 'Self'),
        array(
            'relation' => 'AND',
            array('key' => 'family_member_id', 'compare' => 'NOT EXISTS'),
            array('key' => 'assigned_user_id', 'compare' => 'NOT EXISTS')
        )
    )
));

echo "<table border='1' cellpadding='5'>
<tr>
    <th>ID</th>
    <th>Title</th>
</tr>";
foreach ($refill_meds as $rm) {
    echo "<tr><td>{$rm->ID}</td><td>{$rm->post_title}</td></tr>";
}
echo "</table>";
