<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register "Medication" Custom Post Type
 */
function pillpalnow_register_medication_cpt()
{
    $labels = array(
        'name' => _x('Medications', 'Post Type General Name', 'pillpalnow'),
        'singular_name' => _x('Medication', 'Post Type Singular Name', 'pillpalnow'),
        'menu_name' => __('Medications', 'pillpalnow'),
        'name_admin_bar' => __('Medication', 'pillpalnow'),
        'all_items' => __('All Medications', 'pillpalnow'),
        'add_new_item' => __('Add New Medication', 'pillpalnow'),
        'add_new' => __('Add New', 'pillpalnow'),
        'new_item' => __('New Medication', 'pillpalnow'),
        'edit_item' => __('Edit Medication', 'pillpalnow'),
        'update_item' => __('Update Medication', 'pillpalnow'),
        'view_item' => __('View Medication', 'pillpalnow'),
        'view_items' => __('View Medications', 'pillpalnow'),
        'search_items' => __('Search Medication', 'pillpalnow'),
        'not_found' => __('Not found', 'pillpalnow'),
        'not_found_in_trash' => __('Not found in Trash', 'pillpalnow'),
    );
    $args = array(
        'label' => __('Medication', 'pillpalnow'),
        'description' => __('Medication details and schedules', 'pillpalnow'),
        'labels' => $labels,
        'supports' => array('title', 'editor', 'thumbnail', 'custom-fields'),
        'taxonomies' => array('category', 'post_tag'),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'menu_position' => 5,
        'menu_icon' => 'dashicons-plus-alt',
        'show_in_admin_bar' => true,
        'show_in_nav_menus' => true,
        'can_export' => true,
        'has_archive' => false,
        'exclude_from_search' => true,
        'publicly_queryable' => false,
        'capability_type' => 'post',
        'show_in_rest' => true,
    );
    register_post_type('medication', $args);
}
add_action('init', 'pillpalnow_register_medication_cpt');

/**
 * Register "Dose Log" Copy Type
 */
function pillpalnow_register_dose_log_cpt()
{
    $labels = array(
        'name' => _x('Dose Logs', 'Post Type General Name', 'pillpalnow'),
        'singular_name' => _x('Dose Log', 'Post Type Singular Name', 'pillpalnow'),
        'menu_name' => __('Dose Logs', 'pillpalnow'),
    );
    $args = array(
        'label' => __('Dose Log', 'pillpalnow'),
        'labels' => $labels,
        'supports' => array('title', 'custom-fields', 'author'),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'menu_icon' => 'dashicons-clipboard',
        'capability_type' => 'post',
        'has_archive' => false,
        'exclude_from_search' => true,
    );
    register_post_type('dose_log', $args);
}
add_action('init', 'pillpalnow_register_dose_log_cpt');

/**
 * Register "Refill Request" CPT
 */
function pillpalnow_register_refill_request_cpt()
{
    $labels = array(
        'name' => _x('Refill Requests', 'Post Type General Name', 'pillpalnow'),
        'singular_name' => _x('Refill Request', 'Post Type Singular Name', 'pillpalnow'),
        'menu_name' => __('Refill Requests', 'pillpalnow'),
    );
    $args = array(
        'label' => __('Refill Request', 'pillpalnow'),
        'labels' => $labels,
        'supports' => array('title', 'custom-fields', 'author'),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'menu_icon' => 'dashicons-cart',
        'capability_type' => 'post',
        'has_archive' => false,
        'exclude_from_search' => true,
    );
    register_post_type('refill_request', $args);
}
add_action('init', 'pillpalnow_register_refill_request_cpt');

/**
 * Register "Family Member" CPT
 * Represents patient profiles - people who take medications
 * Can be the primary user (self) or dependents (child, spouse, parent, etc)
 */
function pillpalnow_register_family_member_cpt()
{
    $labels = array(
        'name' => _x('Family Members', 'Post Type General Name', 'pillpalnow'),
        'singular_name' => _x('Family Member', 'Post Type Singular Name', 'pillpalnow'),
        'menu_name' => __('Family Members', 'pillpalnow'),
        'name_admin_bar' => __('Family Member', 'pillpalnow'),
        'all_items' => __('All Family Members', 'pillpalnow'),
        'add_new_item' => __('Add New Family Member', 'pillpalnow'),
        'add_new' => __('Add New', 'pillpalnow'),
        'new_item' => __('New Family Member', 'pillpalnow'),
        'edit_item' => __('Edit Family Member', 'pillpalnow'),
        'update_item' => __('Update Family Member', 'pillpalnow'),
        'view_item' => __('View Family Member', 'pillpalnow'),
        'search_items' => __('Search Family Member', 'pillpalnow'),
        'not_found' => __('Not found', 'pillpalnow'),
        'not_found_in_trash' => __('Not found in Trash', 'pillpalnow'),
    );
    $args = array(
        'label' => __('Family Member', 'pillpalnow'),
        'description' => __('Patient profiles for medication tracking and management', 'pillpalnow'),
        'labels' => $labels,
        'supports' => array('title', 'thumbnail', 'custom-fields', 'author'),
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => false,
        'menu_icon' => 'dashicons-groups',
        'menu_position' => 6,
        'capability_type' => 'post',
        'has_archive' => false,
        'rewrite' => array('slug' => 'family-member'),
        'show_in_rest' => false,
        'exclude_from_search' => true,
    );
    register_post_type('family_member', $args);
}
add_action('init', 'pillpalnow_register_family_member_cpt');

/**
 * Register "Reminder Log" CPT
 * Stores active/pending reminders.
 * Fields: user_id, medication_id, scheduled_datetime, status (pending|dismissed)
 */
function pillpalnow_register_reminder_log_cpt()
{
    $labels = array(
        'name' => _x('Reminder Logs', 'Post Type General Name', 'pillpalnow'),
        'singular_name' => _x('Reminder Log', 'Post Type Singular Name', 'pillpalnow'),
        'menu_name' => __('Reminder Logs', 'pillpalnow'),
    );
    $args = array(
        'label' => __('Reminder Log', 'pillpalnow'),
        'labels' => $labels,
        'supports' => array('title', 'custom-fields', 'author'),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'menu_icon' => 'dashicons-bell',
        'capability_type' => 'post',
        'has_archive' => false,
        'exclude_from_search' => true,
    );
    register_post_type('reminder_log', $args);
}
add_action('init', 'pillpalnow_register_reminder_log_cpt');



