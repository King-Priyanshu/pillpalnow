<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Meta_Box_Manager
 * Manages meta box visibility and structured display for PillPalNow CPTs
 */
class PillPalNow_Meta_Box_Manager
{
    public function __construct()
    {
        // Hide default custom fields meta box for specific CPTs
        add_action('add_meta_boxes', array($this, 'remove_custom_fields_metabox'), 99);

        // Add relationship info meta boxes
        add_action('add_meta_boxes', array($this, 'add_relationship_metaboxes'));
    }

    /**
     * Remove the default Custom Fields meta box from dose_log, reminder_log, refill_request, family_member, and medication
     */
    public function remove_custom_fields_metabox()
    {
        $post_types = array('dose_log', 'reminder_log', 'refill_request', 'family_member', 'medication');

        foreach ($post_types as $post_type) {
            remove_meta_box('postcustom', $post_type, 'normal');
        }
    }

    /**
     * Add structured relationship info meta boxes
     */
    public function add_relationship_metaboxes()
    {
        // Dose Log Relationship Info
        add_meta_box(
            'pillpalnow_dose_log_relationship',
            __('Relationship Info', 'pillpalnow'),
            array($this, 'render_dose_log_relationship_metabox'),
            'dose_log',
            'side',
            'high'
        );

        // Reminder Log Relationship Info
        add_meta_box(
            'pillpalnow_reminder_log_relationship',
            __('Relationship Info', 'pillpalnow'),
            array($this, 'render_reminder_log_relationship_metabox'),
            'reminder_log',
            'side',
            'high'
        );

        // Refill Request Relationship Info
        add_meta_box(
            'pillpalnow_refill_request_relationship',
            __('Relationship Info', 'pillpalnow'),
            array($this, 'render_refill_request_relationship_metabox'),
            'refill_request',
            'side',
            'high'
        );

        // Family Member Details
        add_meta_box(
            'pillpalnow_family_member_details',
            __('Family Member Details', 'pillpalnow'),
            array($this, 'render_family_member_details_metabox'),
            'family_member',
            'normal',
            'high'
        );
    }

    /**
     * Render Dose Log Relationship Meta Box
     */
    public function render_dose_log_relationship_metabox($post)
    {
        $user_id = get_post_meta($post->ID, 'user_id', true);
        if (!$user_id) {
            $user_id = $post->post_author;
        }

        $medication_id = get_post_meta($post->ID, 'medication_id', true);
        $status = get_post_meta($post->ID, 'status', true);
        $log_date = get_post_meta($post->ID, 'log_date', true);
        $log_time = get_post_meta($post->ID, 'log_time', true);
        $is_auto = get_post_meta($post->ID, 'is_missed_auto', true);

        $user = get_userdata($user_id);
        $medication = get_post($medication_id);

        ?>
        <div class="pillpalnow-relationship-info">
            <p>
                <strong>
                    <?php _e('User:', 'pillpalnow'); ?>
                </strong><br>
                <?php echo $user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : __('N/A', 'pillpalnow'); ?>
            </p>
            <p>
                <strong>
                    <?php _e('Medication:', 'pillpalnow'); ?>
                </strong><br>
                <?php
                if ($medication) {
                    $edit_url = get_edit_post_link($medication_id);
                    printf('<a href="%s">%s</a>', esc_url($edit_url), esc_html($medication->post_title));
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                ?>
            </p>
            <p>
                <strong>
                    <?php _e('Date & Time:', 'pillpalnow'); ?>
                </strong><br>
                <?php echo $log_date ? esc_html($log_date . ' ' . $log_time) : __('N/A', 'pillpalnow'); ?>
            </p>
            <p>
                <strong>
                    <?php _e('Status:', 'pillpalnow'); ?>
                </strong><br>
                <span class="pillpalnow-status-badge-inline pillpalnow-status-<?php echo esc_attr($status); ?>">
                    <?php echo $status ? esc_html(ucfirst($status)) : __('N/A', 'pillpalnow'); ?>
                </span>
            </p>
            <p>
                <strong>
                    <?php _e('Source:', 'pillpalnow'); ?>
                </strong><br>
                <?php echo $is_auto ? '<span class="pillpalnow-source-auto">Auto-Generated</span>' : '<span class="pillpalnow-source-user">User Action</span>'; ?>
            </p>
        </div>
        <style>
            .pillpalnow-relationship-info p {
                margin-bottom: 12px;
            }

            .pillpalnow-relationship-info p:last-child {
                margin-bottom: 0;
            }

            .pillpalnow-status-badge-inline {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .pillpalnow-status-taken {
                background-color: #d4edda;
                color: #155724;
            }

            .pillpalnow-status-missed {
                background-color: #f8d7da;
                color: #721c24;
            }

            .pillpalnow-status-skipped {
                background-color: #fff3cd;
                color: #856404;
            }

            .pillpalnow-status-postponed {
                background-color: #d1ecf1;
                color: #0c5460;
            }

            .pillpalnow-status-superseded {
                background-color: #f5f5f5;
                color: #6c757d;
            }

            .pillpalnow-source-auto {
                color: #856404;
                font-style: italic;
            }

            .pillpalnow-source-user {
                color: #155724;
                font-weight: 600;
            }
        </style>
        <?php
    }

    /**
     * Render Reminder Log Relationship Meta Box
     */
    public function render_reminder_log_relationship_metabox($post)
    {
        $user_id = get_post_meta($post->ID, 'user_id', true);
        if (!$user_id) {
            $user_id = $post->post_author;
        }

        $medication_id = get_post_meta($post->ID, 'medication_id', true);
        $status = get_post_meta($post->ID, 'status', true);
        $scheduled_datetime = get_post_meta($post->ID, 'scheduled_datetime', true);
        $postponed_until = get_post_meta($post->ID, 'postponed_until', true);

        $user = get_userdata($user_id);
        $medication = get_post($medication_id);

        ?>
        <div class="pillpalnow-relationship-info">
            <p>
                <strong>
                    <?php _e('User:', 'pillpalnow'); ?>
                </strong><br>
                <?php echo $user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : __('N/A', 'pillpalnow'); ?>
            </p>
            <p>
                <strong>
                    <?php _e('Medication:', 'pillpalnow'); ?>
                </strong><br>
                <?php
                if ($medication) {
                    $edit_url = get_edit_post_link($medication_id);
                    printf('<a href="%s">%s</a>', esc_url($edit_url), esc_html($medication->post_title));
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                ?>
            </p>
            <p>
                <strong>
                    <?php _e('Scheduled DateTime:', 'pillpalnow'); ?>
                </strong><br>
                <?php echo $scheduled_datetime ? esc_html(date('Y-m-d H:i', $scheduled_datetime)) : __('N/A', 'pillpalnow'); ?>
            </p>
            <p>
                <strong>
                    <?php _e('Status:', 'pillpalnow'); ?>
                </strong><br>
                <span class="pillpalnow-status-badge-inline pillpalnow-status-<?php echo esc_attr($status); ?>">
                    <?php echo $status ? esc_html(ucfirst($status)) : __('N/A', 'pillpalnow'); ?>
                </span>
            </p>
            <?php if ($status === 'postponed' && $postponed_until): ?>
                <p>
                    <strong>
                        <?php _e('Postponed Until:', 'pillpalnow'); ?>
                    </strong><br>
                    <?php echo esc_html(date('Y-m-d H:i', $postponed_until)); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Refill Request Relationship Meta Box
     */
    public function render_refill_request_relationship_metabox($post)
    {
        $user_id = $post->post_author;
        $medication_id = get_post_meta($post->ID, 'medication_id', true);
        $quantity = get_post_meta($post->ID, 'quantity', true);
        $status = get_post_meta($post->ID, 'status', true);
        $notes = get_post_meta($post->ID, 'notes', true);

        $user = get_userdata($user_id);
        $medication = get_post($medication_id);

        ?>
        <div class="pillpalnow-relationship-info">
            <p>
                <strong>
                    <?php _e('User:', 'pillpalnow'); ?>
                </strong><br>
                <?php echo $user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : __('N/A', 'pillpalnow'); ?>
            </p>
            <p>
                <strong>
                    <?php _e('Medication:', 'pillpalnow'); ?>
                </strong><br>
                <?php
                if ($medication) {
                    $edit_url = get_edit_post_link($medication_id);
                    printf('<a href="%s">%s</a>', esc_url($edit_url), esc_html($medication->post_title));
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                ?>
            </p>
            <p>
                <strong>
                    <?php _e('Quantity Requested:', 'pillpalnow'); ?>
                </strong><br>
                <?php echo $quantity ? esc_html($quantity) : __('N/A', 'pillpalnow'); ?>
            </p>
            <p>
                <strong>
                    <?php _e('Status:', 'pillpalnow'); ?>
                </strong><br>
                <span class="pillpalnow-status-badge-inline pillpalnow-status-<?php echo esc_attr($status); ?>">
                    <?php echo $status ? esc_html(ucfirst($status)) : __('N/A', 'pillpalnow'); ?>
                </span>
            </p>
            <?php if ($notes): ?>
                <p>
                    <strong>
                        <?php _e('Notes:', 'pillpalnow'); ?>
                    </strong><br>
                    <?php echo esc_html($notes); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Family Member Details Meta Box
     */
    public function render_family_member_details_metabox($post)
    {
        // Add nonce for security
        wp_nonce_field('pillpalnow_family_member_details_nonce', 'pillpalnow_family_member_details_nonce_field');

        $parent_user_id = get_post_field('post_author', $post->ID);
        $relation_type = get_post_meta($post->ID, 'relation_type', true);
        if (!$relation_type) {
            // Fallback to legacy 'relation' field
            $relation_type = get_post_meta($post->ID, 'relation', true);
        }
        if (!$relation_type) {
            $relation_type = 'other'; // Default
        }

        $status = get_post_meta($post->ID, 'status', true);
        if (!$status) {
            $status = 'active'; // Default
        }

        $email = get_post_meta($post->ID, 'email', true);
        $linked_user_id = get_post_meta($post->ID, 'linked_user_id', true);

        $parent_user = get_userdata($parent_user_id);
        ?>
        <div class="pillpalnow-family-member-details">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php _e('Parent User (Account Owner)', 'pillpalnow'); ?></label>
                    </th>
                    <td>
                        <?php if ($parent_user): ?>
                            <strong><?php echo esc_html($parent_user->display_name); ?></strong>
                            <br><small><?php echo esc_html($parent_user->user_email); ?> (ID:
                                <?php echo $parent_user_id; ?>)</small>
                            <br><em style="color: #666;">This is the account owner who created this family member profile.</em>
                        <?php else: ?>
                            <em><?php _e('N/A', 'pillpalnow'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="relation_type"><?php _e('Relation Type', 'pillpalnow'); ?></label>
                    </th>
                    <td>
                        <select name="relation_type" id="relation_type" class="regular-text">
                            <option value="self" <?php selected($relation_type, 'self'); ?>><?php _e('Self', 'pillpalnow'); ?>
                            </option>
                            <option value="child" <?php selected($relation_type, 'child'); ?>><?php _e('Child', 'pillpalnow'); ?>
                            </option>
                            <option value="spouse" <?php selected($relation_type, 'spouse'); ?>>
                                <?php _e('Spouse', 'pillpalnow'); ?></option>
                            <option value="parent" <?php selected($relation_type, 'parent'); ?>>
                                <?php _e('Parent', 'pillpalnow'); ?></option>
                            <option value="other" <?php selected($relation_type, 'other'); ?>><?php _e('Other', 'pillpalnow'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('Relationship of this person to the account owner', 'pillpalnow'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="status"><?php _e('Status', 'pillpalnow'); ?></label>
                    </th>
                    <td>
                        <label style="margin-right: 20px;">
                            <input type="radio" name="status" value="active" <?php checked($status, 'active'); ?>>
                            <?php _e('Active', 'pillpalnow'); ?>
                        </label>
                        <label>
                            <input type="radio" name="status" value="inactive" <?php checked($status, 'inactive'); ?>>
                            <?php _e('Inactive', 'pillpalnow'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Inactive members will not appear in medication assignment dropdowns', 'pillpalnow'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="family_member_email"><?php _e('Email (Optional)', 'pillpalnow'); ?></label>
                    </th>
                    <td>
                        <input type="email" name="family_member_email" id="family_member_email"
                            value="<?php echo esc_attr($email); ?>" class="regular-text">
                        <?php if ($linked_user_id): ?>
                            <?php $linked_user = get_userdata($linked_user_id); ?>
                            <?php if ($linked_user): ?>
                                <br><small style="color: #46b450;">
                                    <span class="dashicons dashicons-yes-alt" style="font-size: 14px; vertical-align: middle;"></span>
                                    <?php printf(__('Linked to user: %s (ID: %d)', 'pillpalnow'), esc_html($linked_user->display_name), $linked_user_id); ?>
                                </small>
                            <?php endif; ?>
                        <?php endif; ?>
                        <p class="description">
                            <?php _e('If this email matches an existing WordPress user, they will be linked automatically', 'pillpalnow'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <style>
            .pillpalnow-family-member-details .form-table th {
                width: 200px;
                padding: 15px 10px 15px 0;
            }

            .pillpalnow-family-member-details .form-table td {
                padding: 15px 10px;
            }
        </style>
        <?php
    }
}

// Save Family Member meta box data
add_action('save_post_family_member', 'pillpalnow_save_family_member_metabox', 10, 2);

function pillpalnow_save_family_member_metabox($post_id, $post)
{
    // Check nonce
    if (
        !isset($_POST['pillpalnow_family_member_details_nonce_field']) ||
        !wp_verify_nonce($_POST['pillpalnow_family_member_details_nonce_field'], 'pillpalnow_family_member_details_nonce')
    ) {
        return;
    }

    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save relation_type
    if (isset($_POST['relation_type'])) {
        $relation_type = sanitize_text_field($_POST['relation_type']);
        update_post_meta($post_id, 'relation_type', $relation_type);
        // Also update legacy 'relation' field for backward compatibility
        update_post_meta($post_id, 'relation', $relation_type);
    }

    // Save status
    if (isset($_POST['status'])) {
        $status = sanitize_text_field($_POST['status']);
        update_post_meta($post_id, 'status', $status);
    }

    // Save email and check for linked user
    if (isset($_POST['family_member_email'])) {
        $email = sanitize_email($_POST['family_member_email']);
        update_post_meta($post_id, 'email', $email);

        // Auto-link to existing user if email matches
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                update_post_meta($post_id, 'linked_user_id', $user->ID);
            } else {
                delete_post_meta($post_id, 'linked_user_id');
            }
        } else {
            delete_post_meta($post_id, 'linked_user_id');
        }
    }

    // Ensure primary_user_id is set (for new entries)
    $primary_user_id = get_post_meta($post_id, 'primary_user_id', true);
    if (!$primary_user_id) {
        update_post_meta($post_id, 'primary_user_id', $post->post_author);
    }
}

// Initialize the class
new PillPalNow_Meta_Box_Manager();
