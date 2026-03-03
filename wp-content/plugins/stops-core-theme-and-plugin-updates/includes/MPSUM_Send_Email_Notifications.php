<?php
if (!defined('ABSPATH')) die('No direct access.');

/**
 * Controller class for configuring and sending notifications emails upon the completion or failure of a plugin, theme, translation, or core background update (automatic updates)
 */
class MPSUM_Send_Email_Notifications {

	/**
	 * Holds the class instance.
	 *
	 * @var object
	 */
	private static $instance = null;

	/**
	 * Set a class instance.
	 */
	public static function get_instance() {
		if (null == self::$instance) {
			self::$instance = new self;
		}
		return self::$instance;
	} //end get_instance

	/**
	 * Class constructor.
	 *
	 * Read in the options and determine which notifications are disabled.
	 */
	private function __construct() {

		$core_options = MPSUM_Updates_Manager::get_options('core');
		add_filter('automatic_updates_send_debug_email', '__return_false', PHP_INT_MAX - 10);
		$send_email = !isset($core_options['notification_core_update_emails']) || 'on' === $core_options['notification_core_update_emails'];
		$send_email = $send_email || !isset($core_options['plugin_auto_updates_notification_emails']) || 'on' === $core_options['plugin_auto_updates_notification_emails'];
		$send_email = $send_email || !isset($core_options['theme_auto_updates_notification_emails']) || 'on' === $core_options['theme_auto_updates_notification_emails'];
		$send_email = $send_email || !isset($core_options['translation_auto_updates_notification_emails']) || 'on' === $core_options['translation_auto_updates_notification_emails'];
		if ($send_email) {
			add_action('automatic_updates_complete', array($this, 'send_notification_emails'), 100, 1);
		}
		add_filter('auto_core_update_send_email', '__return_false', PHP_INT_MAX - 10);
		add_filter('auto_plugin_update_send_email', '__return_false', PHP_INT_MAX - 10, 2);
		add_filter('auto_theme_update_send_email', '__return_false', PHP_INT_MAX - 10, 2);
		if (isset($core_options['notification_core_update_emails']) && 'off' === $core_options['notification_core_update_emails']) {
			add_filter('send_core_update_notification_email', '__return_false', PHP_INT_MAX - 10);
		} else {
			add_filter('send_core_update_notification_email', array($this, 'email_flood_control'), PHP_INT_MAX - 10);
		}

	} //end constructor

	/**
	 * Send notification emails
	 *
	 * @param array $update_results The results of all attempted updates
	 */
	public function send_notification_emails($update_results) {
		if (empty($update_results)) return;
		$core_options = MPSUM_Updates_Manager::get_options('core');
		$body = array();
		$failures = 0;

		// Core
		if ((!isset($core_options['notification_core_update_emails']) || 'on' === $core_options['notification_core_update_emails']) && isset($update_results['core'])) {
			$result = $update_results['core'][0];
			if ($result->result && ! is_wp_error($result->result)) {
				/* translators: %s: WordPress version. */
				$body[] = sprintf(__('SUCCESS: WordPress was successfully updated to %s'), $result->name); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
			} else {
				/* translators: %s: WordPress version. */
				$body[] = sprintf(__('FAILED: WordPress failed to update to %s'), $result->name); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
				$failures++;
			}
			$body[] = '';
		}

		// Plugins, Themes, Translations
		$entities = array();
		if (!isset($core_options['plugin_auto_updates_notification_emails']) || 'on' === $core_options['plugin_auto_updates_notification_emails']) $entities[] = 'plugin';
		if (!isset($core_options['theme_auto_updates_notification_emails']) || 'on' === $core_options['theme_auto_updates_notification_emails']) $entities[] = 'theme';
		if (!isset($core_options['translation_auto_updates_notification_emails']) || 'on' === $core_options['translation_auto_updates_notification_emails']) $entities[] = 'translation';

		foreach ($entities as $type) {
			if (!isset($update_results[$type])) {
				if (false !== ($key = array_search($type, $entities))) {
					unset($entities[$key]);
				}
				continue;
			}
			$success_items = array();
			foreach ($update_results[$type] as $key => $item) {
				if ($item->result && !is_wp_error($item->result)) {
					$success_items[] = $item;
				} elseif (empty($item->name) || empty($item->item->current_version) || empty($item->item->new_version)) {
					unset($update_results[$type][$key]);
				}
			}
			$update_results[$type] = array_values($update_results[$type]);

			if ($success_items) {
				$messages = array(
					'plugin'      => __('The following plugins were successfully updated:'), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
					'theme'       => __('The following themes were successfully updated:'), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
					'translation' => __('The following translations were successfully updated:'), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
				);

				$body[] = $messages[$type];
				if (in_array($type, array('plugin', 'theme'))) {
					foreach ($success_items as $entity) {
						$url = isset($entity->item->url) && '' !== $entity->item->url ? ' - '.$entity->item->url : '';
						/* Translators: 1: Name of item, 2: Current version, 3: New version, 4: Item URL. */
						$body[] = ' * ' . sprintf(__('SUCCESS: %1$s (from version %2$s to %3$s)%4$s'), $entity->name, $entity->item->current_version, $entity->item->new_version, $url); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
					}
				} else {
					foreach (wp_list_pluck($success_items, 'name') as $name) {
						/* translators: %s is Name of item. */
						$body[] = ' * ' . sprintf(__('SUCCESS: %s'), $name); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
					}
				}
				$body[] = '';
			}

			if ($success_items !== $update_results[$type]) {
				// Failed updates.
				$messages = array(
					'plugin'      => __('The following plugins failed to update:'), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
					'theme'       => __('The following themes failed to update:'), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
					'translation' => __('The following translations failed to update:'), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
				);

				$body[] = $messages[$type];

				foreach ($update_results[$type] as $item) {
					if (!$item->result || is_wp_error($item->result)) {
						/* translators: %s is Name of item. */
						$body[] = ' * ' . sprintf(__('FAILED: %s'), $item->name); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
						$failures++;
					}
				}
				$body[] = '';
			}
		}

		$site_title = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

		if ($failures) {
			/* translators: %s is Site title. */
			$subject = sprintf(__('[%s] Background Update Failed'), $site_title); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
		} else {
			/* translators: %s is Site title. */
			$subject = sprintf(__('[%s] Background Update Finished'), $site_title); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
		}

		if (!empty($body)) {
			/* translators: %s: Network home URL. */
			array_unshift($body, sprintf(__('WordPress site: %s'), network_home_url('/'))); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
			/* Translators: %s is the label name. */
			$body[] = trim(sprintf(__("Thanks! -- The %s team", 'stops-core-theme-and-plugin-updates'), apply_filters('eum_whitelabel_name', __('Easy Updates Manager', 'stops-core-theme-and-plugin-updates'))));
			$body[] = '';
	
			$body[] = trim(__('UPDATE LOG ==========')); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
			$body[] = '';
		}

		if (!isset($core_options['notification_core_update_emails']) || 'on' === $core_options['notification_core_update_emails']) $entities[] = 'core';

		foreach ($entities as $type) {
			if (!isset($update_results[$type])) {
				if (false !== ($key = array_search($type, $entities))) {
					unset($entities[$key]);
				}
				continue;
			}

			foreach ($update_results[$type] as $update) {
				$body[] = $update->name;
				$body[] = str_repeat('-', strlen($update->name));

				foreach ($update->messages as $message) {
					$body[] = '  ' . html_entity_decode(str_replace('&#8230;', '...', $message));
				}

				if (is_wp_error($update->result)) {
					$results = array('update' => $update->result);

					if ('rollback_was_required' === $update->result->get_error_code()) {
						$results = (array) $update->result->get_error_data();
					}

					foreach ($results as $result_type => $result) {
						if (!is_wp_error($result)) {
							continue;
						}

						if ('rollback' === $result_type) {
							/* translators: 1: Error code, 2: Error message. */
							$body[] = '  ' . sprintf(__('Rollback Error: [%1$s] %2$s'), $result->get_error_code(), $result->get_error_message()); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
						} else {
							/* translators: 1: Error code, 2: Error message. */
							$body[] = '  ' . sprintf(__('Error: [%1$s] %2$s'), $result->get_error_code(), $result->get_error_message()); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core handles the translation.
						}
						if ($result->get_error_data()) {
							$body[] = '         ' . implode(', ', (array) $result->get_error_data());
						}
					}
				}

				$body[] = '';
			}
		}
		if (!empty($entities) && !empty($body)) {
			$email = array(
				'to' => get_site_option('admin_email'),
				'subject' => $subject,
				'body' => implode("\n", $body),
				'headers' => '',
			);
			$email = $this->maybe_change_automatic_update_email($email);
			wp_mail($email['to'], wp_specialchars_decode($email['subject']), $email['body'], $email['headers']);
		}
	}

	/**
	 * Flood control WordPress core update notifications; called by the WP filter send_core_update_notification_email
	 *
	 * @since 8.0.6
	 * @access public
	 * @see __construct
	 *
	 * @param bool $value Whether to send emails or not.
	 *
	 * @return bool Whether to send emails or not.
	 */
	public function email_flood_control($value) {
		$no_core_email_before = get_site_option('eum_no_core_email_before');
		if (!$no_core_email_before || time() > $no_core_email_before) {
			// Set site option for the next 24 hours to prevent users from being overwhelmed with emails.
			update_site_option('eum_no_core_email_before', apply_filters('eum_no_core_email_before', time() + 86400));
			return $value;
		}
		// Blocked because we've already been here in the last 24 hours
		return false;
	}

	/**
	 * Maybe change automatic update email
	 *
	 * @since 6.1.0
	 * @access public
	 * @see __construct
	 *
	 * @param array $email array
	 *
	 * @return array email array
	 */
	public function maybe_change_automatic_update_email( $email ) {
		$core_options = MPSUM_Updates_Manager::get_options('core');
		$email_addresses = isset($core_options['email_addresses']) ? $core_options['email_addresses'] : array();
		$email_addresses_to_override = array();
		foreach ($email_addresses as $emails) {
			if (is_email($emails)) {
				$email_addresses_to_override[] = $emails;
			}
		}
		if (! empty($email_addresses_to_override)) {
			$email['to'] = $email_addresses_to_override;
		}
		return $email;
	}
}
