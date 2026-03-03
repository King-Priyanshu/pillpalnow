<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PillPalNow_Admin_Columns
 * Manages custom admin columns, filters, and sorting for PillPalNow CPTs
 */
class PillPalNow_Admin_Columns
{
    public function __construct()
    {
        // Medications
        add_filter('manage_medication_posts_columns', array($this, 'medication_columns'));
        add_action('manage_medication_posts_custom_column', array($this, 'medication_column_content'), 10, 2);
        add_filter('manage_edit-medication_sortable_columns', array($this, 'medication_sortable_columns'));
        add_action('restrict_manage_posts', array($this, 'medication_filters'));
        add_filter('parse_query', array($this, 'medication_filter_query'));

        // Dose Logs
        add_filter('manage_dose_log_posts_columns', array($this, 'dose_log_columns'));
        add_action('manage_dose_log_posts_custom_column', array($this, 'dose_log_column_content'), 10, 2);
        add_filter('manage_edit-dose_log_sortable_columns', array($this, 'dose_log_sortable_columns'));
        add_action('restrict_manage_posts', array($this, 'dose_log_filters'));
        add_filter('parse_query', array($this, 'dose_log_filter_query'));

        // Reminder Logs
        add_filter('manage_reminder_log_posts_columns', array($this, 'reminder_log_columns'));
        add_action('manage_reminder_log_posts_custom_column', array($this, 'reminder_log_column_content'), 10, 2);
        add_filter('manage_edit-reminder_log_sortable_columns', array($this, 'reminder_log_sortable_columns'));
        add_action('restrict_manage_posts', array($this, 'reminder_log_filters'));
        add_filter('parse_query', array($this, 'reminder_log_filter_query'));

        // Refill Requests
        add_filter('manage_refill_request_posts_columns', array($this, 'refill_request_columns'));
        add_action('manage_refill_request_posts_custom_column', array($this, 'refill_request_column_content'), 10, 2);
        add_filter('manage_edit-refill_request_sortable_columns', array($this, 'refill_request_sortable_columns'));
        add_action('restrict_manage_posts', array($this, 'refill_request_filters'));
        add_filter('parse_query', array($this, 'refill_request_filter_query'));

        // Family Members
        add_filter('manage_family_member_posts_columns', array($this, 'family_member_columns'));
        add_action('manage_family_member_posts_custom_column', array($this, 'family_member_column_content'), 10, 2);
        add_filter('manage_edit-family_member_sortable_columns', array($this, 'family_member_sortable_columns'));
        add_action('restrict_manage_posts', array($this, 'family_member_filters'));
        add_filter('parse_query', array($this, 'family_member_filter_query'));

        // Custom sorting queries
        add_filter('posts_orderby', array($this, 'custom_orderby'), 10, 2);

        // Add custom CSS for status badges
        add_action('admin_head', array($this, 'admin_column_styles'));
    }

    // ========================================
    // MEDICATIONS CPT
    // ========================================

    public function medication_columns($columns)
    {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = $columns['title'];
        $new_columns['assigned_user'] = __('Assigned User', 'pillpalnow');
        $new_columns['stock_quantity'] = __('Stock Qty', 'pillpalnow');
        $new_columns['refill_threshold'] = __('Refill Threshold', 'pillpalnow');
        $new_columns['schedule_type'] = __('Schedule Type', 'pillpalnow');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public function medication_column_content($column, $post_id)
    {
        switch ($column) {
            case 'assigned_user':
                $assigned_to = get_post_meta($post_id, 'assigned_to', true);
                $assigned_user_id = get_post_meta($post_id, 'assigned_user_id', true);
                $family_member_id = get_post_meta($post_id, 'family_member_id', true);

                if ($family_member_id) {
                    // Medication is assigned to a family member
                    $family_member = get_post($family_member_id);
                    if ($family_member) {
                        $parent_user_id = get_post_field('post_author', $family_member_id);
                        $parent_user = get_userdata($parent_user_id);
                        $relation = get_post_meta($family_member_id, 'relation_type', true);
                        if (!$relation) {
                            $relation = get_post_meta($family_member_id, 'relation', true);
                        }

                        printf(
                            '<strong>%s</strong>',
                            esc_html($family_member->post_title)
                        );
                        if ($relation) {
                            printf(
                                ' <span style="font-size: 11px; color: #666;">(%s)</span>',
                                esc_html(ucfirst($relation))
                            );
                        }
                        if ($parent_user) {
                            printf(
                                '<br><small style="color: #999;">via %s</small>',
                                esc_html($parent_user->display_name)
                            );
                        }
                    } else {
                        echo esc_html($assigned_to);
                    }
                } elseif ($assigned_user_id) {
                    $user = get_userdata($assigned_user_id);
                    echo $user ? esc_html($user->display_name) : esc_html($assigned_to);
                } elseif ($assigned_to) {
                    echo esc_html($assigned_to);
                } else {
                    $author = get_userdata(get_post_field('post_author', $post_id));
                    echo $author ? esc_html($author->display_name) : __('N/A', 'pillpalnow');
                }
                break;

            case 'stock_quantity':
                $stock = get_post_meta($post_id, 'stock_quantity', true);
                echo $stock !== '' ? intval($stock) : '0';
                break;

            case 'refill_threshold':
                $threshold = get_post_meta($post_id, 'refill_threshold', true);
                echo $threshold !== '' ? intval($threshold) : __('N/A', 'pillpalnow');
                break;

            case 'schedule_type':
                $schedule = get_post_meta($post_id, 'schedule_type', true);
                echo $schedule ? esc_html(ucfirst(str_replace('_', ' ', $schedule))) : __('N/A', 'pillpalnow');
                break;
        }
    }

    public function medication_sortable_columns($columns)
    {
        $columns['stock_quantity'] = 'stock_quantity';
        $columns['schedule_type'] = 'schedule_type';
        return $columns;
    }

    public function medication_filters($post_type)
    {
        if ($post_type !== 'medication') {
            return;
        }

        // User Filter
        $selected_user = isset($_GET['medication_user']) ? intval($_GET['medication_user']) : 0;
        $users = get_users(array('fields' => array('ID', 'display_name')));

        echo '<select name="medication_user">';
        echo '<option value="">' . __('All Users', 'pillpalnow') . '</option>';
        foreach ($users as $user) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($user->ID),
                selected($selected_user, $user->ID, false),
                esc_html($user->display_name)
            );
        }
        echo '</select>';

        // Schedule Type Filter
        $selected_schedule = isset($_GET['medication_schedule']) ? sanitize_text_field($_GET['medication_schedule']) : '';
        $schedules = array('daily' => 'Daily', 'weekly' => 'Weekly', 'as_needed' => 'As Needed');

        echo '<select name="medication_schedule">';
        echo '<option value="">' . __('All Schedules', 'pillpalnow') . '</option>';
        foreach ($schedules as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($selected_schedule, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public function medication_filter_query($query)
    {
        global $pagenow, $typenow;

        if ($pagenow === 'edit.php' && $typenow === 'medication') {
            $meta_query = array();

            if (!empty($_GET['medication_user'])) {
                $meta_query[] = array(
                    'key' => 'assigned_user_id',
                    'value' => intval($_GET['medication_user']),
                    'compare' => '='
                );
            }

            if (!empty($_GET['medication_schedule'])) {
                $meta_query[] = array(
                    'key' => 'schedule_type',
                    'value' => sanitize_text_field($_GET['medication_schedule']),
                    'compare' => '='
                );
            }

            if (!empty($meta_query)) {
                $query->set('meta_query', $meta_query);
            }
        }
    }

    // ========================================
    // DOSE LOGS CPT
    // ========================================

    public function dose_log_columns($columns)
    {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['user'] = __('User', 'pillpalnow');
        $new_columns['medication'] = __('Medication', 'pillpalnow');
        $new_columns['log_date'] = __('Date', 'pillpalnow');
        $new_columns['log_time'] = __('Time', 'pillpalnow');
        $new_columns['status'] = __('Status', 'pillpalnow');
        $new_columns['source'] = __('Source', 'pillpalnow');
        return $new_columns;
    }

    public function dose_log_column_content($column, $post_id)
    {
        switch ($column) {
            case 'user':
                $user_id = get_post_meta($post_id, 'user_id', true);
                if (!$user_id) {
                    $user_id = get_post_field('post_author', $post_id);
                }
                $user = get_userdata($user_id);
                echo $user ? esc_html($user->display_name) : __('N/A', 'pillpalnow');
                break;

            case 'medication':
                $med_id = get_post_meta($post_id, 'medication_id', true);
                if ($med_id && get_post($med_id)) {
                    $edit_url = get_edit_post_link($med_id);
                    $med_title = get_the_title($med_id);

                    // Check if medication is assigned to a family member
                    $family_member_id = get_post_meta($med_id, 'family_member_id', true);

                    printf(
                        '<a href="%s">%s</a>',
                        esc_url($edit_url),
                        esc_html($med_title)
                    );

                    if ($family_member_id) {
                        $family_member = get_post($family_member_id);
                        if ($family_member) {
                            $relation = get_post_meta($family_member_id, 'relation_type', true);
                            if (!$relation) {
                                $relation = get_post_meta($family_member_id, 'relation', true);
                            }

                            printf(
                                '<br><small style="color: #666;">→ %s',
                                esc_html($family_member->post_title)
                            );
                            if ($relation) {
                                printf(' (%s)', esc_html(ucfirst($relation)));
                            }
                            echo '</small>';
                        }
                    }
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                break;

            case 'log_date':
                $date = get_post_meta($post_id, 'log_date', true);
                echo $date ? esc_html($date) : __('N/A', 'pillpalnow');
                break;

            case 'log_time':
                $time = get_post_meta($post_id, 'log_time', true);
                echo $time ? esc_html($time) : __('N/A', 'pillpalnow');
                break;

            case 'status':
                $status = get_post_meta($post_id, 'status', true);
                $this->render_status_badge($status);
                break;

            case 'source':
                $is_auto = get_post_meta($post_id, 'is_missed_auto', true);
                echo $is_auto ? '<span class="pillpalnow-source-auto">Auto</span>' : '<span class="pillpalnow-source-user">User</span>';
                break;
        }
    }

    public function dose_log_sortable_columns($columns)
    {
        $columns['log_date'] = 'log_date';
        $columns['log_time'] = 'log_time';
        $columns['status'] = 'status';
        return $columns;
    }

    public function dose_log_filters($post_type)
    {
        if ($post_type !== 'dose_log') {
            return;
        }

        // User Filter
        $selected_user = isset($_GET['dose_log_user']) ? intval($_GET['dose_log_user']) : 0;
        $users = get_users(array('fields' => array('ID', 'display_name')));

        echo '<select name="dose_log_user">';
        echo '<option value="">' . __('All Users', 'pillpalnow') . '</option>';
        foreach ($users as $user) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($user->ID),
                selected($selected_user, $user->ID, false),
                esc_html($user->display_name)
            );
        }
        echo '</select>';

        // Medication Filter
        $selected_med = isset($_GET['dose_log_medication']) ? intval($_GET['dose_log_medication']) : 0;
        $medications = get_posts(array('post_type' => 'medication', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));

        echo '<select name="dose_log_medication">';
        echo '<option value="">' . __('All Medications', 'pillpalnow') . '</option>';
        foreach ($medications as $med) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($med->ID),
                selected($selected_med, $med->ID, false),
                esc_html($med->post_title)
            );
        }
        echo '</select>';

        // Status Filter
        $selected_status = isset($_GET['dose_log_status']) ? sanitize_text_field($_GET['dose_log_status']) : '';
        $statuses = array('taken' => 'Taken', 'missed' => 'Missed', 'skipped' => 'Skipped', 'postponed' => 'Postponed', 'superseded' => 'Superseded');

        echo '<select name="dose_log_status">';
        echo '<option value="">' . __('All Statuses', 'pillpalnow') . '</option>';
        foreach ($statuses as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($selected_status, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';

        // Source Filter
        $selected_source = isset($_GET['dose_log_source']) ? sanitize_text_field($_GET['dose_log_source']) : '';

        echo '<select name="dose_log_source">';
        echo '<option value="">' . __('All Sources', 'pillpalnow') . '</option>';
        printf('<option value="user"%s>User</option>', selected($selected_source, 'user', false));
        printf('<option value="auto"%s>Auto</option>', selected($selected_source, 'auto', false));
        echo '</select>';
    }

    public function dose_log_filter_query($query)
    {
        global $pagenow, $typenow;

        if ($pagenow === 'edit.php' && $typenow === 'dose_log') {
            $meta_query = array();

            if (!empty($_GET['dose_log_user'])) {
                $meta_query[] = array(
                    'key' => 'user_id',
                    'value' => intval($_GET['dose_log_user']),
                    'compare' => '='
                );
            }

            if (!empty($_GET['dose_log_medication'])) {
                $meta_query[] = array(
                    'key' => 'medication_id',
                    'value' => intval($_GET['dose_log_medication']),
                    'compare' => '='
                );
            }

            if (!empty($_GET['dose_log_status'])) {
                $meta_query[] = array(
                    'key' => 'status',
                    'value' => sanitize_text_field($_GET['dose_log_status']),
                    'compare' => '='
                );
            }

            if (!empty($_GET['dose_log_source'])) {
                if ($_GET['dose_log_source'] === 'auto') {
                    $meta_query[] = array(
                        'key' => 'is_missed_auto',
                        'value' => '1',
                        'compare' => '='
                    );
                } elseif ($_GET['dose_log_source'] === 'user') {
                    $meta_query[] = array(
                        'relation' => 'OR',
                        array(
                            'key' => 'is_missed_auto',
                            'compare' => 'NOT EXISTS'
                        ),
                        array(
                            'key' => 'is_missed_auto',
                            'value' => '1',
                            'compare' => '!='
                        )
                    );
                }
            }

            if (!empty($meta_query)) {
                $query->set('meta_query', $meta_query);
            }
        }
    }

    // ========================================
    // REMINDER LOGS CPT
    // ========================================

    public function reminder_log_columns($columns)
    {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['user'] = __('User', 'pillpalnow');
        $new_columns['medication'] = __('Medication', 'pillpalnow');
        $new_columns['scheduled_datetime'] = __('Scheduled DateTime', 'pillpalnow');
        $new_columns['status'] = __('Status', 'pillpalnow');
        $new_columns['postponed_until'] = __('Postponed Until', 'pillpalnow');
        return $new_columns;
    }

    public function reminder_log_column_content($column, $post_id)
    {
        switch ($column) {
            case 'user':
                $user_id = get_post_meta($post_id, 'user_id', true);
                if (!$user_id) {
                    $user_id = get_post_field('post_author', $post_id);
                }
                $user = get_userdata($user_id);
                echo $user ? esc_html($user->display_name) : __('N/A', 'pillpalnow');
                break;

            case 'medication':
                $med_id = get_post_meta($post_id, 'medication_id', true);
                if ($med_id && get_post($med_id)) {
                    $edit_url = get_edit_post_link($med_id);
                    $med_title = get_the_title($med_id);

                    // Check if medication is assigned to a family member
                    $family_member_id = get_post_meta($med_id, 'family_member_id', true);

                    printf(
                        '<a href="%s">%s</a>',
                        esc_url($edit_url),
                        esc_html($med_title)
                    );

                    if ($family_member_id) {
                        $family_member = get_post($family_member_id);
                        if ($family_member) {
                            $relation = get_post_meta($family_member_id, 'relation_type', true);
                            if (!$relation) {
                                $relation = get_post_meta($family_member_id, 'relation', true);
                            }

                            printf(
                                '<br><small style="color: #666;">→ %s',
                                esc_html($family_member->post_title)
                            );
                            if ($relation) {
                                printf(' (%s)', esc_html(ucfirst($relation)));
                            }
                            echo '</small>';
                        }
                    }
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                break;

            case 'scheduled_datetime':
                $timestamp = get_post_meta($post_id, 'scheduled_datetime', true);
                if ($timestamp) {
                    echo esc_html(date('Y-m-d H:i', $timestamp));
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                break;

            case 'status':
                $status = get_post_meta($post_id, 'status', true);
                $this->render_status_badge($status);
                break;

            case 'postponed_until':
                $status = get_post_meta($post_id, 'status', true);
                if ($status === 'postponed') {
                    $until = get_post_meta($post_id, 'postponed_until', true);
                    echo $until ? esc_html(date('Y-m-d H:i', $until)) : __('N/A', 'pillpalnow');
                } else {
                    echo '—';
                }
                break;
        }
    }

    public function reminder_log_sortable_columns($columns)
    {
        $columns['scheduled_datetime'] = 'scheduled_datetime';
        $columns['status'] = 'status';
        return $columns;
    }

    public function reminder_log_filters($post_type)
    {
        if ($post_type !== 'reminder_log') {
            return;
        }

        // User Filter
        $selected_user = isset($_GET['reminder_log_user']) ? intval($_GET['reminder_log_user']) : 0;
        $users = get_users(array('fields' => array('ID', 'display_name')));

        echo '<select name="reminder_log_user">';
        echo '<option value="">' . __('All Users', 'pillpalnow') . '</option>';
        foreach ($users as $user) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($user->ID),
                selected($selected_user, $user->ID, false),
                esc_html($user->display_name)
            );
        }
        echo '</select>';

        // Medication Filter
        $selected_med = isset($_GET['reminder_log_medication']) ? intval($_GET['reminder_log_medication']) : 0;
        $medications = get_posts(array('post_type' => 'medication', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));

        echo '<select name="reminder_log_medication">';
        echo '<option value="">' . __('All Medications', 'pillpalnow') . '</option>';
        foreach ($medications as $med) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($med->ID),
                selected($selected_med, $med->ID, false),
                esc_html($med->post_title)
            );
        }
        echo '</select>';

        // Status Filter
        $selected_status = isset($_GET['reminder_log_status']) ? sanitize_text_field($_GET['reminder_log_status']) : '';
        $statuses = array('pending' => 'Pending', 'postponed' => 'Postponed', 'taken' => 'Taken', 'missed' => 'Missed', 'skipped' => 'Skipped', 'superseded' => 'Superseded');

        echo '<select name="reminder_log_status">';
        echo '<option value="">' . __('All Statuses', 'pillpalnow') . '</option>';
        foreach ($statuses as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($selected_status, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public function reminder_log_filter_query($query)
    {
        global $pagenow, $typenow;

        if ($pagenow === 'edit.php' && $typenow === 'reminder_log') {
            $meta_query = array();

            if (!empty($_GET['reminder_log_user'])) {
                $meta_query[] = array(
                    'key' => 'user_id',
                    'value' => intval($_GET['reminder_log_user']),
                    'compare' => '='
                );
            }

            if (!empty($_GET['reminder_log_medication'])) {
                $meta_query[] = array(
                    'key' => 'medication_id',
                    'value' => intval($_GET['reminder_log_medication']),
                    'compare' => '='
                );
            }

            if (!empty($_GET['reminder_log_status'])) {
                $meta_query[] = array(
                    'key' => 'status',
                    'value' => sanitize_text_field($_GET['reminder_log_status']),
                    'compare' => '='
                );
            }

            if (!empty($meta_query)) {
                $query->set('meta_query', $meta_query);
            }
        }
    }

    // ========================================
    // REFILL REQUESTS CPT
    // ========================================

    public function refill_request_columns($columns)
    {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['user'] = __('User', 'pillpalnow');
        $new_columns['medication'] = __('Medication', 'pillpalnow');
        $new_columns['quantity'] = __('Quantity', 'pillpalnow');
        $new_columns['status'] = __('Status', 'pillpalnow');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public function refill_request_column_content($column, $post_id)
    {
        switch ($column) {
            case 'user':
                $user_id = get_post_field('post_author', $post_id);
                $user = get_userdata($user_id);
                echo $user ? esc_html($user->display_name) : __('N/A', 'pillpalnow');
                break;

            case 'medication':
                $med_id = get_post_meta($post_id, 'medication_id', true);
                if ($med_id && get_post($med_id)) {
                    $edit_url = get_edit_post_link($med_id);
                    $med_title = get_the_title($med_id);

                    // Check if medication is assigned to a family member
                    $family_member_id = get_post_meta($med_id, 'family_member_id', true);

                    printf(
                        '<a href="%s">%s</a>',
                        esc_url($edit_url),
                        esc_html($med_title)
                    );

                    if ($family_member_id) {
                        $family_member = get_post($family_member_id);
                        if ($family_member) {
                            $relation = get_post_meta($family_member_id, 'relation_type', true);
                            if (!$relation) {
                                $relation = get_post_meta($family_member_id, 'relation', true);
                            }

                            printf(
                                '<br><small style="color: #666;">→ %s',
                                esc_html($family_member->post_title)
                            );
                            if ($relation) {
                                printf(' (%s)', esc_html(ucfirst($relation)));
                            }
                            echo '</small>';
                        }
                    }
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                break;

            case 'quantity':
                // Auto-refill saves as 'remaining_qty', manual refill saves as 'quantity'
                $qty = get_post_meta($post_id, 'remaining_qty', true);
                if (!$qty) {
                    $qty = get_post_meta($post_id, 'quantity', true);
                }
                echo $qty ? esc_html($qty) : __('N/A', 'pillpalnow');
                break;

            case 'status':
                $status = get_post_meta($post_id, 'status', true);
                $this->render_status_badge($status);
                break;
        }
    }

    public function refill_request_sortable_columns($columns)
    {
        $columns['quantity'] = 'quantity';
        $columns['status'] = 'status';
        return $columns;
    }

    public function refill_request_filters($post_type)
    {
        if ($post_type !== 'refill_request') {
            return;
        }

        // User Filter
        $selected_user = isset($_GET['refill_request_user']) ? intval($_GET['refill_request_user']) : 0;
        $users = get_users(array('fields' => array('ID', 'display_name')));

        echo '<select name="refill_request_user">';
        echo '<option value="">' . __('All Users', 'pillpalnow') . '</option>';
        foreach ($users as $user) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($user->ID),
                selected($selected_user, $user->ID, false),
                esc_html($user->display_name)
            );
        }
        echo '</select>';

        // Status Filter
        $selected_status = isset($_GET['refill_request_status']) ? sanitize_text_field($_GET['refill_request_status']) : '';
        $statuses = array('pending' => 'Pending', 'approved' => 'Approved', 'completed' => 'Completed', 'rejected' => 'Rejected');

        echo '<select name="refill_request_status">';
        echo '<option value="">' . __('All Statuses', 'pillpalnow') . '</option>';
        foreach ($statuses as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($selected_status, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public function refill_request_filter_query($query)
    {
        global $pagenow, $typenow;

        if ($pagenow === 'edit.php' && $typenow === 'refill_request') {
            if (!empty($_GET['refill_request_user'])) {
                $query->set('author', intval($_GET['refill_request_user']));
            }

            if (!empty($_GET['refill_request_status'])) {
                $query->set('meta_query', array(
                    array(
                        'key' => 'status',
                        'value' => sanitize_text_field($_GET['refill_request_status']),
                        'compare' => '='
                    )
                ));
            }
        }
    }

    // ========================================
    // FAMILY MEMBERS CPT
    // ========================================

    public function family_member_columns($columns)
    {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Family Member', 'pillpalnow');
        $new_columns['parent_user'] = __('Parent User', 'pillpalnow');
        $new_columns['relation'] = __('Relation', 'pillpalnow');
        $new_columns['medications'] = __('Medications', 'pillpalnow');
        $new_columns['status'] = __('Status', 'pillpalnow');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public function family_member_column_content($column, $post_id)
    {
        switch ($column) {
            case 'parent_user':
                // Get primary user (post_author is the account owner)
                $user_id = get_post_field('post_author', $post_id);
                $user = get_userdata($user_id);
                if ($user) {
                    printf(
                        '<a href="%s">%s</a><br><small>ID: %d</small>',
                        esc_url(admin_url('user-edit.php?user_id=' . $user_id)),
                        esc_html($user->display_name),
                        $user_id
                    );
                } else {
                    echo __('N/A', 'pillpalnow');
                }
                break;

            case 'relation':
                $relation = get_post_meta($post_id, 'relation_type', true);
                if (!$relation) {
                    // Fallback to legacy 'relation' field
                    $relation = get_post_meta($post_id, 'relation', true);
                }
                if ($relation) {
                    $class = 'pillpalnow-relation-badge pillpalnow-relation-' . esc_attr($relation);
                    printf(
                        '<span class="%s">%s</span>',
                        $class,
                        esc_html(ucfirst(str_replace('_', ' ', $relation)))
                    );
                } else {
                    echo '<span class="pillpalnow-relation-badge pillpalnow-relation-other">Other</span>';
                }
                break;

            case 'medications':
                global $wpdb;
                // Count medications assigned to this family member
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                    WHERE pm.meta_key = 'family_member_id' 
                    AND pm.meta_value = %d
                    AND p.post_type = 'medication'
                    AND p.post_status = 'publish'",
                    $post_id
                ));

                if ($count > 0) {
                    $filter_url = add_query_arg(
                        array(
                            'post_type' => 'medication',
                            'medication_family_member' => $post_id
                        ),
                        admin_url('edit.php')
                    );
                    printf(
                        '<a href="%s"><strong>%d</strong></a>',
                        esc_url($filter_url),
                        intval($count)
                    );
                } else {
                    echo '<span style="color: #999;">0</span>';
                }
                break;

            case 'status':
                $status = get_post_meta($post_id, 'status', true);
                if (!$status) {
                    $status = 'active'; // Default
                }
                $this->render_status_badge($status);
                break;
        }
    }

    public function family_member_sortable_columns($columns)
    {
        $columns['relation'] = 'relation_type';
        $columns['status'] = 'status';
        return $columns;
    }

    public function family_member_filters($post_type)
    {
        if ($post_type !== 'family_member') {
            return;
        }

        // Parent User Filter
        $selected_user = isset($_GET['family_member_user']) ? intval($_GET['family_member_user']) : 0;
        $users = get_users(array('fields' => array('ID', 'display_name')));

        echo '<select name="family_member_user">';
        echo '<option value="">' . __('All Parent Users', 'pillpalnow') . '</option>';
        foreach ($users as $user) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($user->ID),
                selected($selected_user, $user->ID, false),
                esc_html($user->display_name)
            );
        }
        echo '</select>';

        // Relation Type Filter
        $selected_relation = isset($_GET['family_member_relation']) ? sanitize_text_field($_GET['family_member_relation']) : '';
        $relations = array(
            'self' => 'Self',
            'child' => 'Child',
            'spouse' => 'Spouse',
            'parent' => 'Parent',
            'other' => 'Other'
        );

        echo '<select name="family_member_relation">';
        echo '<option value="">' . __('All Relations', 'pillpalnow') . '</option>';
        foreach ($relations as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($selected_relation, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';

        // Status Filter
        $selected_status = isset($_GET['family_member_status']) ? sanitize_text_field($_GET['family_member_status']) : '';
        $statuses = array('active' => 'Active', 'inactive' => 'Inactive');

        echo '<select name="family_member_status">';
        echo '<option value="">' . __('All Statuses', 'pillpalnow') . '</option>';
        foreach ($statuses as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($selected_status, $key, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public function family_member_filter_query($query)
    {
        global $pagenow, $typenow;

        if ($pagenow === 'edit.php' && $typenow === 'family_member') {
            $meta_query = array();

            if (!empty($_GET['family_member_user'])) {
                $query->set('author', intval($_GET['family_member_user']));
            }

            if (!empty($_GET['family_member_relation'])) {
                $meta_query[] = array(
                    'relation' => 'OR',
                    array(
                        'key' => 'relation_type',
                        'value' => sanitize_text_field($_GET['family_member_relation']),
                        'compare' => '='
                    ),
                    array(
                        'key' => 'relation',
                        'value' => sanitize_text_field($_GET['family_member_relation']),
                        'compare' => '='
                    )
                );
            }

            if (!empty($_GET['family_member_status'])) {
                $meta_query[] = array(
                    'key' => 'status',
                    'value' => sanitize_text_field($_GET['family_member_status']),
                    'compare' => '='
                );
            }

            if (!empty($meta_query)) {
                $query->set('meta_query', $meta_query);
            }
        }
    }

    // ========================================
    // HELPERS
    // ========================================

    private function render_status_badge($status)
    {
        $status = strtolower($status);
        $class = 'pillpalnow-status-badge pillpalnow-status-' . esc_attr($status);
        $label = ucfirst($status);
        printf('<span class="%s">%s</span>', $class, esc_html($label));
    }

    public function custom_orderby($orderby, $query)
    {
        global $wpdb;

        if (!is_admin() || !$query->is_main_query()) {
            return $orderby;
        }

        $order = $query->get('order');
        $orderby_param = $query->get('orderby');

        // Handle meta field sorting
        $meta_sortable = array(
            'stock_quantity',
            'schedule_type',
            'log_date',
            'log_time',
            'status',
            'scheduled_datetime',
            'quantity'
        );

        if (in_array($orderby_param, $meta_sortable)) {
            $orderby = "(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = '{$orderby_param}' LIMIT 1) {$order}";
        }

        return $orderby;
    }

    public function admin_column_styles()
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array('medication', 'dose_log', 'reminder_log', 'refill_request', 'family_member'))) {
            return;
        }
        ?>
        <style>
            .pillpalnow-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                line-height: 1.4;
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

            .pillpalnow-status-pending {
                background-color: #e2e3e5;
                color: #383d41;
            }

            .pillpalnow-status-superseded {
                background-color: #f5f5f5;
                color: #6c757d;
                text-decoration: line-through;
            }

            .pillpalnow-status-approved {
                background-color: #d4edda;
                color: #155724;
            }

            .pillpalnow-status-completed {
                background-color: #b8daff;
                color: #004085;
            }

            .pillpalnow-status-rejected {
                background-color: #f8d7da;
                color: #721c24;
            }

            .pillpalnow-source-auto {
                color: #856404;
                font-style: italic;
            }

            .pillpalnow-source-user {
                color: #155724;
                font-weight: 600;
            }

            /* Relation badges */
            .pillpalnow-relation-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                line-height: 1.4;
            }

            .pillpalnow-relation-self {
                background-color: #d1ecf1;
                color: #0c5460;
            }

            .pillpalnow-relation-child {
                background-color: #d4edda;
                color: #155724;
            }

            .pillpalnow-relation-spouse {
                background-color: #f8d7da;
                color: #721c24;
            }

            .pillpalnow-relation-parent {
                background-color: #fff3cd;
                color: #856404;
            }

            .pillpalnow-relation-other {
                background-color: #e2e3e5;
                color: #383d41;
            }
        </style>
        <?php
    }
}

// Initialize the class
new PillPalNow_Admin_Columns();
