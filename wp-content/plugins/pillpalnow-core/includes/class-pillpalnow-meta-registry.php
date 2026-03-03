<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Meta_Registry
 * 
 * Registers post meta fields with proper schemas for REST API support.
 * Fixes notices about missing schema.items for array types.
 */
class PillPalNow_Meta_Registry
{
    /**
     * Initialize meta registration
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_meta'), 20); // Run after CPT registration
    }

    /**
     * Register meta fields
     */
    public static function register_meta()
    {
        // 1. Dose Times (Array of Objects)
        // Stored as: [{"time": "08:00", "dosage": "1"}]
        register_post_meta('medication', 'dose_times', array(
            'type' => 'array', // Has to be array if show_in_rest is schema
            'single' => true,  // It is a single meta value containing serialized array (handled by WP as array in rest if schema provided? No, careful)
            // Wait, register_post_meta 'type'=>'array' means return value is [ value1, value2 ] if single=false
            // If single=true, it expects the value ITSELF to be an array? 
            // In WordPress meta, if single=true, it returns one value. If that value is serialized array, WP handles it.
            // BUT for REST API, 'type' => 'array' implies the JSON representation is an array.

            // To fix the specific notice: "When registering an 'array' meta type to show_in_rest, you must specify the schema for each array item"
            'show_in_rest' => array(
                'schema' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'time' => array(
                                'type' => 'string',
                            ),
                            'dosage' => array(
                                'type' => 'string',
                            ),
                        ),
                    ),
                ),
            ),
            'auth_callback' => function () {
                return current_user_can('edit_posts'); }
        ));

        // 2. Selected Weekdays (Array of Strings)
        // Stored as: ["mon", "wed", "fri"]
        register_post_meta('medication', 'selected_weekdays', array(
            'type' => 'array',
            'single' => true,
            'show_in_rest' => array(
                'schema' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'string',
                    ),
                ),
            ),
            'auth_callback' => function () {
                return current_user_can('edit_posts'); }
        ));
    }
}
