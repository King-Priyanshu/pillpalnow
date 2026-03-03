<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ensure required classes are loaded before using them
$notifications_file = dirname(__FILE__) . '/class-pillpalnow-notifications.php';
if (file_exists($notifications_file) && !class_exists('PillPalNow_Notifications')) {
    require_once $notifications_file;
}

/**
 * Register API Endpoints
 */
add_action('rest_api_init', function () {
    // 1. Drug Search Endpoint (Typeahead)
    register_rest_route('pillpalnow/v1', '/drug-search', array(
        'methods' => 'GET',
        'callback' => 'pillpalnow_rest_drug_search',
        'permission_callback' => '__return_true',
        'args' => array(
            'keyword' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_string($param) && !empty($param);
                }
            ),
        ),
    ));

    // 2. Drug RxCUI Lookup Endpoint (Selection)
    register_rest_route('pillpalnow/v1', '/drug-rxcui', array(
        'methods' => 'GET',
        'callback' => 'pillpalnow_rest_drug_rxcui',
        'permission_callback' => '__return_true',
        'args' => array(
            'name' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_string($param) && !empty($param);
                }
            ),
        ),
    ));

    // 3. Drug Details Endpoint (Optional usage for existing logic)
    register_rest_route('pillpalnow/v1', '/drug-name', array(
        'methods' => 'GET',
        'callback' => 'pillpalnow_rest_drug_name',
        'permission_callback' => '__return_true',
        'args' => array(
            'rxcui' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

    // 4. Cloud Sync Endpoint (Preserved)
    register_rest_route('pillpalnow/v1', '/sync', [
        'methods' => 'POST',
        'callback' => 'pillpalnow_cloud_sync',
        'permission_callback' => array('Pro_Middleware', 'check_pro_access')
    ]);

    // 5. Stripe Webhook Endpoint
    register_rest_route('pillpalnow/v1', '/stripe-webhook', array(
        'methods' => 'POST',
        'callback' => array('PillPalNow_Stripe_Webhook_Handler', 'handle_request'),
        'permission_callback' => '__return_true', // Stripe handles signature verification
    ));

    // 5. Get Notifications Endpoint
    register_rest_route('pillpalnow/v1', '/notifications', array(
        'methods' => 'GET',
        'callback' => 'pillpalnow_rest_get_notifications',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'limit' => array(
                'default' => 20,
                'sanitize_callback' => 'absint',
            ),
            'status' => array(
                'default' => 'all',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'offset' => array(
                'default' => 0,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    // 6. Get Unread Count Endpoint
    register_rest_route('pillpalnow/v1', '/notifications/unread-count', array(
        'methods' => 'GET',
        'callback' => 'pillpalnow_rest_get_unread_count',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ));

    // 7. Mark Notifications as Read Endpoint
    register_rest_route('pillpalnow/v1', '/notifications/mark-read', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_mark_notifications_read',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'notification_ids' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_array($param) || is_numeric($param);
                },
            ),
        ),
    ));

    // 8. Delete Notification Endpoint (Soft Delete)
    register_rest_route('pillpalnow/v1', '/notifications/delete', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_delete_notifications',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'notification_ids' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_array($param) || is_numeric($param);
                },
            ),
        ),
    ));

    // 9. Store OneSignal Player ID
    register_rest_route('pillpalnow/v1', '/onesignal/player-id', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_store_onesignal_player_id',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'player_id' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && !empty($param);
                },
            ),
        ),
    ));
});
/**
 * Endpoint 1: Search for approximate RxNorm matches (Typeahead)
 * URL: /wp-json/pillpalnow/v1/drug-search?keyword=aspirin
 */
function pillpalnow_rest_drug_search($request)
{
    $keyword = sanitize_text_field($request->get_param('keyword'));

    if (empty($keyword)) {
        return wp_send_json_error(array('message' => 'Keyword is required'), 400);
    }

    // Create a transient key based on the keyword (hashed for safety)
    $transient_key = 'pillpalnow_drug_search_v3_' . md5($keyword);
    $cached_data = get_transient($transient_key);

    if (false !== $cached_data) {
        return wp_send_json_success($cached_data);
    }

    // RxNav approximateTerm API
    // Use approximateTerm for better partial/fuzzy matching than spellingsuggestions
    // https://rxnav.nlm.nih.gov/REST/approximateTerm.json?term={keyword}&maxEntries=20
    if (!pillpalnow_check_ip_rate_limit()) {
        return wp_send_json_error(array('message' => 'Rate limit exceeded'), 429);
    }

    $api_url = 'https://rxnav.nlm.nih.gov/REST/approximateTerm.json';
    $api_url = add_query_arg(array(
        'term' => $keyword,
        'maxEntries' => 40 // Fetch more to filter down
    ), $api_url);

    $response = wp_remote_get($api_url, array(
        'timeout' => 10,
        'sslverify' => true
    ));

    if (is_wp_error($response)) {
        return wp_send_json_error(array('message' => 'External API error'), 500);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data)) {
        return wp_send_json_error(array('message' => 'Invalid response from RxNav'), 502);
    }

    // Parse and Normalize Output
    // Structure: { "approximateGroup": { "candidate": [ { "rxcui": "...", "name": "...", "score": "..." } ] } }
    $suggestions = [];
    $seen_names = [];

    if (isset($data['approximateGroup']['candidate']) && is_array($data['approximateGroup']['candidate'])) {
        foreach ($data['approximateGroup']['candidate'] as $candidate) {
            if (isset($candidate['name']) && isset($candidate['rxcui'])) {
                $name = $candidate['name'];
                $rxcui = $candidate['rxcui'];

                // Clean name (sometimes contains [Brand] etc, keep it as is for precision or clean it?)
                // For now, keep as is.

                // Deduplicate by Name (Case Insensitive)
                $name_lower = strtolower($name);
                if (!isset($seen_names[$name_lower])) {
                    $suggestions[] = array(
                        'label' => $name,
                        'value' => $rxcui
                    );
                    $seen_names[$name_lower] = true;
                }
            }
        }
    }

    // Cache the result for 24 hours
    $suggestions = array_slice($suggestions, 0, 15);
    set_transient($transient_key, $suggestions, 24 * HOUR_IN_SECONDS);

    return wp_send_json_success($suggestions);
}

/**
 * Endpoint 2: Get RxCUI from Drug Name (Selection)
 * URL: /wp-json/pillpalnow/v1/drug-rxcui?name=aspirin
 */
function pillpalnow_rest_drug_rxcui($request)
{
    $name = sanitize_text_field($request->get_param('name'));

    if (empty($name)) {
        return wp_send_json_error(array('message' => 'Drug name is required'), 400);
    }

    // Create a transient key based on the drug name
    $transient_key = 'pillpalnow_drug_rxcui_v2_' . md5($name);
    $cached_data = get_transient($transient_key);

    if (false !== $cached_data) {
        return wp_send_json_success($cached_data);
    }

    if (!pillpalnow_check_ip_rate_limit()) {
        return wp_send_json_error(array('message' => 'Rate limit exceeded'), 429);
    }

    // RxNav rxcui API
    // https://rxnav.nlm.nih.gov/REST/rxcui.json?name={drug_name}
    $api_url = 'https://rxnav.nlm.nih.gov/REST/rxcui.json';
    $api_url = add_query_arg(array(
        'name' => $name
    ), $api_url);

    $response = wp_remote_get($api_url, array('timeout' => 5, 'sslverify' => true));

    if (is_wp_error($response)) {
        return wp_send_json_error(array('message' => 'External API error'), 500);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Extract RxCUI
    // Structure: { "idGroup": { "rxnormId": [ "..." ] } }
    // Or sometimes just { "idGroup": "..." } if no match? Documentation says idGroup object always exists.
    // However, if no match, rxnormId might be missing.
    $rxcui = null;
    if (isset($data['idGroup']['rxnormId']) && is_array($data['idGroup']['rxnormId']) && count($data['idGroup']['rxnormId']) > 0) {
        $rxcui = $data['idGroup']['rxnormId'][0];
    }

    if (!$rxcui) {
        return wp_send_json_error(array('message' => 'No RxCUI found for this drug name'), 404);
    }

    // Cache the result for 24 hours (RxCUI mapping is stable)
    set_transient($transient_key, array('rxcui' => $rxcui), 24 * HOUR_IN_SECONDS);

    return wp_send_json_success(array('rxcui' => $rxcui));
}

/**
 * Endpoint 3: Get normalized drug name by RXCUI (Existing)
 * URL: /wp-json/pillpalnow/v1/drug-name?rxcui=198440
 */
function pillpalnow_rest_drug_name($request)
{
    $rxcui = sanitize_text_field($request->get_param('rxcui'));

    if (!is_numeric($rxcui)) {
        return wp_send_json_error(array('message' => 'Invalid RXCUI provided'), 400);
    }

    // Create a transient key based on the RXCUI
    $transient_key = 'pillpalnow_drug_name_' . $rxcui;
    $cached_name = get_transient($transient_key);

    if (false !== $cached_name) {
        return wp_send_json_success(array('name' => $cached_name));
    }

    if (!pillpalnow_check_ip_rate_limit()) {
        return wp_send_json_error(array('message' => 'Rate limit exceeded'), 429);
    }

    // RxNav getRxNormName API
    // https://rxnav.nlm.nih.gov/REST/rxcui/{rxcui}.json
    $api_url = 'https://rxnav.nlm.nih.gov/REST/rxcui/' . $rxcui . '.json';

    $response = wp_remote_get($api_url, array('timeout' => 5, 'sslverify' => true));

    if (is_wp_error($response)) {
        return wp_send_json_error(array('message' => 'External API error'), 500);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Extract the name property safely
    // Structure: { "idGroup": { "name": "...", "rxcui": "..." } }
    $name = isset($data['idGroup']['name']) ? $data['idGroup']['name'] : null;

    if (!$name) {
        return wp_send_json_error(array('message' => 'Drug name not found for this RXCUI'), 404);
    }

    // Cache the result for 24 hours
    set_transient($transient_key, $name, 24 * HOUR_IN_SECONDS);

    return wp_send_json_success(array('name' => $name));
}

function pillpalnow_cloud_sync($request)
{
    return array('success' => true, 'message' => 'Synced with cloud successfully.');
}

/**
 * Get Notifications API Callback
 * URL: /wp-json/pillpalnow/v1/notifications
 */
function pillpalnow_rest_get_notifications($request)
{
    $user_id = get_current_user_id();

    $args = array(
        'limit' => $request->get_param('limit'),
        'status' => $request->get_param('status'),
        'offset' => $request->get_param('offset'),
    );

    $notifications = PillPalNow_Notifications::get_notifications($user_id, $args);

    if (!defined('LSCACHE_NO_CACHE')) {
        define('LSCACHE_NO_CACHE', true);
    }

    $response = rest_ensure_response(array(
        'success' => true,
        'notifications' => $notifications,
        'count' => count($notifications),
    ));
    $response->header('Cache-Control', 'no-cache, must-revalidate, max-age=0');
    return $response;
}

/**
 * Get Unread Count API Callback
 * URL: /wp-json/pillpalnow/v1/notifications/unread-count
 */
function pillpalnow_rest_get_unread_count($request)
{
    $user_id = get_current_user_id();
    $count = PillPalNow_Notifications::get_unread_count($user_id);

    if (!defined('LSCACHE_NO_CACHE')) {
        define('LSCACHE_NO_CACHE', true);
    }

    $response = rest_ensure_response(array(
        'success' => true,
        'count' => $count,
    ));
    $response->header('Cache-Control', 'no-cache, must-revalidate, max-age=0');
    return $response;
}

/**
 * Mark Notifications as Read API Callback
 * URL: /wp-json/pillpalnow/v1/notifications/mark-read
 */
function pillpalnow_rest_mark_notifications_read($request)
{
    $user_id = get_current_user_id();
    $notification_ids = $request->get_param('notification_ids');

    // Handle single ID or array
    if (!is_array($notification_ids)) {
        $notification_ids = array($notification_ids);
    }

    $updated_count = PillPalNow_Notifications::mark_as_read($notification_ids, $user_id);

    return rest_ensure_response(array(
        'success' => true,
        'updated_count' => $updated_count,
    ));
}

/**
 * Delete Notifications API Callback
 * URL: /wp-json/pillpalnow/v1/notifications/delete
 */
function pillpalnow_rest_delete_notifications($request)
{
    $user_id = get_current_user_id();
    $notification_ids = $request->get_param('notification_ids');

    // Handle single ID or array
    if (!is_array($notification_ids)) {
        $notification_ids = array($notification_ids);
    }

    $deleted_count = PillPalNow_Notifications::soft_delete($notification_ids, $user_id);

    return rest_ensure_response(array(
        'success' => true,
        'deleted_count' => $deleted_count,
    ));
}


// 5. Reminder Action Endpoint
// 10. Request Refill Endpoint
add_action('rest_api_init', function () {
    register_rest_route('pillpalnow/v1', '/refill/request', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_request_refill',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'medication_id' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ),
            'quantity' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ),
            'notes' => array(
                'required' => false,
                'default' => '',
                'validate_callback' => function ($param) {
                    return is_string($param);
                },
            ),
        ),
    ));

    // 11. Confirm Refill Endpoint
    register_rest_route('pillpalnow/v1', '/refill/confirm', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_confirm_refill',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'medication_id' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ),
            'refill_quantity' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ),
        ),
    ));

    // 12. Snooze Refill Endpoint
    register_rest_route('pillpalnow/v1', '/refill/snooze', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_snooze_refill',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'medication_id' => array(
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ),
            'snooze_duration' => array(
                'required' => false,
                'default' => 24, // Default to 24 hours
                'validate_callback' => function ($param) {
                    return is_numeric($param);
                },
            ),
        ),
    ));
});

/**
 * Request Refill API Callback
 * URL: /wp-json/pillpalnow/v1/refill/request
 */
function pillpalnow_rest_request_refill($request)
{
    $user_id = get_current_user_id();
    $medication_id = intval($request->get_param('medication_id'));
    $quantity = intval($request->get_param('quantity'));
    $notes = sanitize_textarea_field($request->get_param('notes'));

    // Validate medication exists and is accessible to user
    $medication = get_post($medication_id);
    if (!$medication || $medication->post_type !== 'medication') {
        return new WP_Error('invalid_medication', 'Invalid medication', ['status' => 400]);
    }

    // Check if user has permission to request refill for this medication
    $assigned_user_id = get_post_meta($medication_id, 'assigned_user_id', true);
    $is_authorized = false;
    if ($assigned_user_id && $assigned_user_id == $user_id) {
        $is_authorized = true;
    } elseif ((int) $medication->post_author === $user_id) {
        $is_authorized = true;
    }

    if (!$is_authorized) {
        return new WP_Error('unauthorized', 'You are not authorized to request a refill for this medication', ['status' => 403]);
    }

    $medication_title = get_the_title($medication_id);
    $target_user_id = $assigned_user_id ? $assigned_user_id : $user_id;

    $post_id = wp_insert_post(array(
        'post_title' => sprintf('Refill: %s', $medication_title),
        'post_type' => 'refill_request',
        'post_status' => 'publish',
        'post_author' => $target_user_id,
        'meta_input' => array(
            'medication_id' => $medication_id,
            'quantity' => $quantity,
            'notes' => $notes,
            'status' => 'pending',
            'user_id' => $target_user_id
        ),
    ));

    if (!$post_id || is_wp_error($post_id)) {
        return new WP_Error('failed_to_create', 'Failed to create refill request', ['status' => 500]);
    }

    return rest_ensure_response(array(
        'success' => true,
        'refill_request_id' => $post_id,
        'message' => 'Refill request created successfully',
    ));
}

/**
 * Confirm Refill API Callback
 * URL: /wp-json/pillpalnow/v1/refill/confirm
 */
function pillpalnow_rest_confirm_refill($request)
{
    $user_id = get_current_user_id();
    $med_id = intval($request->get_param('medication_id'));
    $new_quantity = intval($request->get_param('refill_quantity'));

    // Validate medication exists and is accessible to user
    $med = get_post($med_id);
    if (!$med || $med->post_type !== 'medication') {
        return new WP_Error('invalid_medication', 'Invalid medication', ['status' => 400]);
    }

    $assigned_user_id = get_post_meta($med_id, 'assigned_user_id', true);
    if ((int) $assigned_user_id !== $user_id) {
        return new WP_Error('unauthorized', 'You are not authorized to confirm refills for this medication', ['status' => 403]);
    }

    // Logic: Add to existing stock instead of replacing
    $current_base_qty = get_post_meta($med_id, '_refill_base_qty', true);

    if ($current_base_qty !== '') {
        $new_base = floatval($current_base_qty) + $new_quantity;
        update_post_meta($med_id, '_refill_base_qty', $new_base);
    } else {
        $current_stock = 0;
        if (function_exists('pillpalnow_get_remaining_stock')) {
            $current_stock = pillpalnow_get_remaining_stock($med_id);
        } else {
            $current_stock = (float) get_post_meta($med_id, 'stock_quantity', true);
        }

        $new_base = $current_stock + $new_quantity;
        update_post_meta($med_id, '_refill_base_qty', $new_base);
        update_post_meta($med_id, '_refill_date', date('Y-m-d'));
    }

    pillpalnow_recalculate_stock($med_id);
    delete_post_meta($med_id, 'refill_snoozed_until');
    delete_post_meta($med_id, '_refill_triggered');
    delete_post_meta($med_id, '_refill_alert_sent_date');

    if (class_exists('PillPalNow_Notifications')) {
        PillPalNow_Notifications::clear_refill_notifications($med_id, $user_id);
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Refill confirmed successfully',
    ));
}

/**
 * Snooze Refill API Callback
 * URL: /wp-json/pillpalnow/v1/refill/snooze
 */
function pillpalnow_rest_snooze_refill($request)
{
    $user_id = get_current_user_id();
    $med_id = intval($request->get_param('medication_id'));
    $snooze_duration = intval($request->get_param('snooze_duration')); // in hours

    // Validate medication exists and is accessible to user
    $med = get_post($med_id);
    if (!$med || $med->post_type !== 'medication') {
        return new WP_Error('invalid_medication', 'Invalid medication', ['status' => 400]);
    }

    $assigned_user_id = get_post_meta($med_id, 'assigned_user_id', true);
    if ((int) $assigned_user_id !== $user_id) {
        return new WP_Error('unauthorized', 'You are not authorized to snooze refills for this medication', ['status' => 403]);
    }

    $snooze_until = strtotime("+$snooze_duration hours");
    update_post_meta($med_id, 'refill_snoozed_until', $snooze_until);

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Refill snoozed successfully',
        'snooze_until' => $snooze_until,
    ));
}
add_action('rest_api_init', function () {
    register_rest_route('pillpalnow/v1', '/reminder-action', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_handle_reminder_action',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'notification_id' => array('required' => true),
            'action' => array('required' => true), // taken, skip, postpone
            'delay_minutes' => array('required' => false, 'default' => 15)
        )
    ));
});

function pillpalnow_handle_reminder_action($request)
{
    $notification_id = $request->get_param('notification_id');
    $action = $request->get_param('action');
    $user_id = get_current_user_id();

    // Verify Notification
    $notif = get_post($notification_id);
    if (!$notif || $notif->post_type !== 'reminder_log' || (int) $notif->post_author !== $user_id) {
        return new WP_Error('invalid_notification', 'Invalid notification', ['status' => 403]);
    }

    // Check Status - Allow 'pending' or 'postponed' (if it reappeared)
    $status = get_post_meta($notification_id, 'status', true);
    if ($status === 'taken' || $status === 'skipped' || $status === 'dismissed') {
        return new WP_Error('already_processed', 'Notification already processed', ['status' => 400]);
    }

    $med_id = get_post_meta($notification_id, 'medication_id', true);

    // STRICT DATA ISOLATION: Verify medication ownership
    $assigned_user_id = get_post_meta($med_id, 'assigned_user_id', true);
    if ((int) $assigned_user_id !== $user_id) {
        return new WP_Error('unauthorized', 'Unauthorized: Medication not assigned to you', ['status' => 403]);
    }

    $scheduled_ts = get_post_meta($notification_id, 'scheduled_datetime', true);

    // SINGLE INSTANCE ENFORCEMENT: Close other active reminders for THIS SPECIFIC DOSE
    // This ensures only one active entry exists at a time for a given dose (within 3-hour window)
    $time_window = 10800; // 3 hours in seconds

    $other_active_reminders = get_posts([
        'post_type' => 'reminder_log',
        'author' => $user_id,
        'posts_per_page' => -1,
        'post__not_in' => [$notification_id], // Exclude current one
        'meta_query' => [
            'relation' => 'AND',
            ['key' => 'medication_id', 'value' => $med_id],
            ['key' => 'scheduled_datetime', 'value' => ($scheduled_ts - $time_window), 'compare' => '>=', 'type' => 'NUMERIC'],
            ['key' => 'scheduled_datetime', 'value' => ($scheduled_ts + $time_window), 'compare' => '<=', 'type' => 'NUMERIC'],
            [
                'relation' => 'OR',
                ['key' => 'status', 'value' => 'pending'],
                ['key' => 'status', 'value' => 'postponed']
            ]
        ]
    ]);

    // Close all other active reminders WITHIN THE TIME WINDOW for this dose
    foreach ($other_active_reminders as $other_rem) {
        update_post_meta($other_rem->ID, 'status', 'superseded');
        update_post_meta($other_rem->ID, 'superseded_by', $notification_id);
        update_post_meta($other_rem->ID, 'superseded_at', current_time('mysql'));
    }

    if ($action === 'taken') {
        // Create History: Taken
        $log_id = wp_insert_post([
            'post_type' => 'dose_log',
            'post_title' => 'Taken: ' . get_the_title($med_id),
            'post_status' => 'publish',
            'post_author' => $user_id
        ]);
        update_post_meta($log_id, 'medication_id', $med_id);
        update_post_meta($log_id, 'user_id', $user_id);
        update_post_meta($log_id, 'status', 'taken');
        update_post_meta($log_id, 'log_date', current_time('Y-m-d'));
        update_post_meta($log_id, 'log_time', current_time('H:i'));

        // Update Reminder Status
        update_post_meta($notification_id, 'status', 'taken');
        update_post_meta($notification_id, 'processed_at', current_time('mysql'));

        // Recalculate Stock & Trigger Actions
        $dose_times = get_post_meta($med_id, 'dose_times', true);
        $dosage_snapshot = 1;
        if (is_array($dose_times)) {
            foreach ($dose_times as $dt) {
                if (isset($dt['dosage']) && is_numeric($dt['dosage'])) {
                    $dosage_snapshot = floatval($dt['dosage']);
                    break;
                }
            }
        }
        update_post_meta($log_id, 'dosage_snapshot', $dosage_snapshot);
        
        if (function_exists('pillpalnow_recalculate_stock')) {
            pillpalnow_recalculate_stock($med_id);
        }
        
        do_action('pillpalnow/dose_logged', $med_id, $log_id, $dosage_snapshot);


    } elseif ($action === 'skip') {
        // Create History: Skipped
        $log_id = wp_insert_post([
            'post_type' => 'dose_log',
            'post_title' => 'Skipped: ' . get_the_title($med_id),
            'post_status' => 'publish',
            'post_author' => $user_id
        ]);
        update_post_meta($log_id, 'medication_id', $med_id);
        update_post_meta($log_id, 'user_id', $user_id);
        update_post_meta($log_id, 'status', 'skipped');
        update_post_meta($log_id, 'log_date', current_time('Y-m-d'));
        update_post_meta($log_id, 'log_time', current_time('H:i'));

        // Update Reminder Status
        update_post_meta($notification_id, 'status', 'skipped');
        update_post_meta($notification_id, 'processed_at', current_time('mysql'));

        // Recalculate Stock & Trigger Actions (Skipped doesn't deduct stock but triggers hooks)
        if (function_exists('pillpalnow_recalculate_stock')) {
            pillpalnow_recalculate_stock($med_id);
        }
        
        do_action('pillpalnow/dose_logged', $med_id, $log_id, 0);


    } elseif ($action === 'postpone') {
        // Postpone
        $postpone_until = $request->get_param('postpone_until'); // Expecting Timestamp

        // Fallback for legacy calls
        if (!$postpone_until) {
            $delay = (int) $request->get_param('delay_minutes');
            if ($delay <= 0)
                $delay = 15;
            $postpone_until = current_time('timestamp') + ($delay * 60);
        }

        // Update Meta
        update_post_meta($notification_id, 'postponed_until', $postpone_until);
        update_post_meta($notification_id, 'status', 'postponed');
    }

    // NOTIFY PARENT when family member acts on a dose
    $family_member_id = get_post_meta($med_id, 'family_member_id', true);
    if ($family_member_id && class_exists('PillPalNow_Notifications')) {
        $medication = get_post($med_id);
        $parent_user_id = (int) $medication->post_author;

        // Only notify parent if they're different from current user
        if ($parent_user_id && $parent_user_id !== $user_id) {
            $family_member_name = get_the_title($family_member_id);
            $med_title = get_the_title($med_id);
            $time_display = current_time('g:i A');

            if ($action === 'taken') {
                PillPalNow_Notifications::create(
                    $parent_user_id,
                    PillPalNow_Notifications::TYPE_TAKEN,
                    "{$family_member_name} took a dose",
                    "{$med_title} at {$time_display}",
                    $med_id,
                    get_permalink($family_member_id)
                );
            } elseif ($action === 'skip') {
                PillPalNow_Notifications::create(
                    $parent_user_id,
                    PillPalNow_Notifications::TYPE_SKIPPED,
                    "{$family_member_name} skipped a dose",
                    "{$med_title} at {$time_display}",
                    $med_id,
                    get_permalink($family_member_id)
                );
            } elseif ($action === 'postpone') {
                $postponed_time_display = date('g:i A', $postpone_until);
                PillPalNow_Notifications::create(
                    $parent_user_id,
                    PillPalNow_Notifications::TYPE_POSTPONED,
                    "{$family_member_name} postponed a dose",
                    "{$med_title} - rescheduled to {$postponed_time_display}",
                    $med_id,
                    get_permalink($family_member_id)
                );
            }
        }
    }

    return array('success' => true);
}

/**
 * Helper: Get Client IP Address (Secure)
 * 
 * Securely detects the client's IP address while preventing spoofing.
 * Validates IPs against private and reserved ranges.
 * 
 * @return string Client IP address
 */
function pillpalnow_get_client_ip()
{
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_X_REAL_IP',          // Nginx proxy
        'HTTP_X_FORWARDED_FOR',    // Standard proxy header
        'REMOTE_ADDR'              // Direct connection
    ];

    foreach ($ip_keys as $key) {
        if (!isset($_SERVER[$key])) {
            continue;
        }

        // Handle comma-separated IPs (from proxy chains)
        $ips = explode(',', $_SERVER[$key]);

        foreach ($ips as $ip) {
            $ip = trim($ip);

            // Validate IP format and exclude private/reserved ranges
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    // Fallback to REMOTE_ADDR without filtering (direct connection)
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/**
 * Helper: Check IP Rate Limit
 * Limits to 60 requests per minute per IP
 * 
 * @param int $limit Maximum requests per minute
 * @return bool True if within limit, false if exceeded
 */
function pillpalnow_check_ip_rate_limit($limit = 60)
{
    if (is_user_logged_in()) {
        return true;
    }

    $ip = pillpalnow_get_client_ip();

    // Use secure hash of IP in transient key
    $transient_name = 'pillpalnow_rate_limit_' . md5($ip);
    $count = get_transient($transient_name);

    if (false === $count) {
        set_transient($transient_name, 1, 60);
    } elseif ($count >= $limit) {
        return false;
    } else {
        // Increment
        set_transient($transient_name, $count + 1, 60);
    }

    return true;
}

/**
 * Endpoint 8: Cancel Subscription
 * URL: /wp-json/pillpalnow/v1/subscription/cancel
 */
add_action('rest_api_init', function () {
    register_rest_route('pillpalnow/v1', '/subscription/cancel', array(
        'methods' => 'POST',
        'callback' => 'pillpalnow_rest_cancel_subscription',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ));
});

/**
 * REST Callback: Cancel Subscription
 */
function pillpalnow_rest_cancel_subscription($request)
{
    $user_id = get_current_user_id();
    $subscription_id = get_user_meta($user_id, 'stripe_subscription_id', true);
    $cancel_immediately = $request->get_param('immediate') === 'true' || $request->get_param('immediate') === true;

    if (!$subscription_id) {
        return wp_send_json_error(array('message' => 'No active subscription found.'), 404);
    }

    try {
        // Retrieve subscription from Stripe
        $sub = \Stripe\Subscription::retrieve($subscription_id);

        if ($sub->status === 'canceled') {
            return wp_send_json_success(array('message' => 'Subscription is already cancelled.'));
        }

        // Check if canceling at period end is already set
        if ($sub->cancel_at_period_end) {
            return wp_send_json_success(array('message' => 'Subscription is already set to cancel at the end of the period.'));
        }

        if ($cancel_immediately) {
            // Cancel immediately
            $sub->cancel();
            $message = 'Your subscription has been cancelled immediately.';
            Subscription_Manager::cancel_subscription($user_id);
        } else {
            // Cancel at period end
            $sub->update(array('cancel_at_period_end' => true));
            $message = 'Your subscription has been scheduled to cancel at the end of the period.';
            Subscription_Manager::update_subscription_details($user_id, 'cancelling', $sub->current_period_end, true);
        }

        return wp_send_json_success(array('message' => $message));

    } catch (\Exception $e) {
        error_log('Stripe Cancel Error: ' . $e->getMessage());
        return wp_send_json_error(array('message' => 'Failed to cancel subscription. Please try again.'), 500);
    }
}

/**
 * Store OneSignal Player ID API Callback
 * URL: /wp-json/pillpalnow/v1/onesignal/player-id
 */
function pillpalnow_rest_store_onesignal_player_id($request)
{
    $user_id = get_current_user_id();
    $player_id = sanitize_text_field($request->get_param('player_id'));

    if (class_exists('PillPalNow_OneSignal_Service')) {
        $onesignal_service = PillPalNow_OneSignal_Service::get_instance();
        $result = $onesignal_service->store_player_id($user_id, $player_id);
        
        if ($result) {
            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Player ID stored successfully'
            ));
        } else {
            return new WP_Error('storage_failed', 'Failed to store player ID', array('status' => 500));
        }
    } else {
        return new WP_Error('service_not_found', 'OneSignal service not available', array('status' => 500));
    }
}

