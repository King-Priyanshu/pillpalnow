<?php
if (!defined('ABSPATH')) die('No direct access.');
echo '<div class="eum-advanced-settings-container force-updates">';

// Check for options that also disable force updates
$options = MPSUM_Updates_Manager::get_options('core');

// Show a notice if all updates are disabled
if (isset($options['all_updates']) && 'off' == $options['all_updates']) {
	printf('<div class="mpsum-error mpsum-bold">%s</div>', esc_html__('All updates are disabled.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('Please re-enable all updates for force updates to work.', 'stops-core-theme-and-plugin-updates'));
}

// Show a notice if automatic updates are off
if (!MPSUM_Utils::get_instance()->is_automatic_updates_enabled()) {
	printf('<div class="mpsum-error mpsum-bold">%s</div>', esc_html__('Automatic updates are off, so Force updates will not work.', 'stops-core-theme-and-plugin-updates'));
}

// Show a warning if delay updates is above zero
if (isset($options['delay_updates']) && $options['delay_updates'] > 0) {
	printf('<div class="mpsum-notice mpsum-bold">%s</div>', esc_html__('Delayed updates are on, so some assets may not be updated automatically.', 'stops-core-theme-and-plugin-updates'));
}

// Begin output
printf('<h3>%s</h3>', esc_html__('Force automatic updates', 'stops-core-theme-and-plugin-updates'));
printf('<div class="mpsum-notice mpsum-regular">%s</div>', esc_html__('Force updates will request automatic updates of your plugins, core, themes, and translations immediately.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('This is useful for debugging and checking that automatic updates are working as intended.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('By default, WordPress checks for updates every 12 hours.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('Running force updates will, if successful, cause updates to happen immediately.', 'stops-core-theme-and-plugin-updates'));
$updates = array();
if (current_user_can('update_core')) $updates[] = __('core', 'stops-core-theme-and-plugin-updates');
if (current_user_can('update_plugins')) $updates[] = __('plugin', 'stops-core-theme-and-plugin-updates');
if (current_user_can('update_themes')) $updates[] = __('theme', 'stops-core-theme-and-plugin-updates');
if (current_user_can('update_themes') || current_user_can('update_plugins')) $updates[] = __('translation', 'stops-core-theme-and-plugin-updates');
if (!$updates) {
	printf('<div class="mpsum-error mpsum-regular">%s</div>', esc_html__("You don't have sufficient user capabilities to force automatic updates.", 'stops-core-theme-and-plugin-updates'));
} else {
	$allowed_entities = '';
	$delimiter = '';
	foreach ($updates as $i => $update) {
		$allowed_entities .= $delimiter.($allowed_entities ? ' ' : '').$update;
		$delimiter = ',';
		if ($allowed_entities && count($updates)-1 == $i+1) $delimiter = ' '.__('and', 'stops-core-theme-and-plugin-updates');
	}
	/* Translators: %s: Allowed update types. */
	if (count($updates) < 4) printf('<div class="mpsum-error mpsum-regular">%s</div>', sprintf(esc_html__("You can only force %s automatic updates due to insufficient user capabilities you have for the website.", 'stops-core-theme-and-plugin-updates'), '<strong>'.esc_html($allowed_entities).'</strong>'));
}
$utils = MPSUM_Utils::get_instance();
$updraftplus = $utils->is_installed('updraftplus');
if (true === $updraftplus['installed'] && true === $updraftplus['active']) {
	global $updraftplus_admin;
	if (is_a($updraftplus_admin, 'UpdraftPlus_Admin') && is_callable(array($updraftplus_admin, 'add_backup_scaffolding'))) {
		printf('<label><input type="checkbox" name="backup_force_updates" id="backup_force_updates" value="1" />%s</label>', esc_html__('Take a backup first (with UpdraftPlus)', 'stops-core-theme-and-plugin-updates'));
		$updraftplus_admin->add_backup_scaffolding(__('Take a backup before update', 'stops-core-theme-and-plugin-updates'), array($updraftplus_admin, 'backupnow_modal_contents'));
	}
} else {
	if (true === $updraftplus['installed'] && false === $updraftplus['active']) {
		$can_activate = is_multisite() ? current_user_can('manage_network_plugins') : current_user_can('activate_plugins');
		if ($can_activate) {
			$activate_link = is_multisite() ? network_admin_url('plugins.php?action=activate&plugin='.$updraftplus['name']) : self_admin_url('plugins.php?action=activate&plugin='.$updraftplus['name']);
			$url = esc_url(wp_nonce_url(
				$activate_link,
				'activate-plugin_'.$updraftplus['name']
			));
			$url_text = __('Follow this link to activate it.', 'stops-core-theme-and-plugin-updates');
			$anchor = "<a href=\"{$url}\">{$url_text}</a>";
		}
		$required_plugin = __('Take a backup with UpdraftPlus before updating.', 'stops-core-theme-and-plugin-updates');
		printf('<p id="eum-auto-backup-description">%s %s</p>', esc_html($required_plugin), wp_kses($anchor, array('a' => array('href' => array()))));
	} else {
		if (current_user_can('install_plugins')) {
			$url = esc_url(wp_nonce_url(
				is_multisite() ? network_admin_url('update.php?action=install-plugin&plugin=updraftcentral') : self_admin_url('update.php?action=install-plugin&plugin=updraftplus'),
				'install-plugin_updraftplus'
			));
			$url_text = __('Follow this link to install it.', 'stops-core-theme-and-plugin-updates');
			$anchor = "<a href=\"{$url}\">{$url_text}</a>";
			$required_plugin = __('You can take backups using UpdraftPlus before updating.', 'stops-core-theme-and-plugin-updates');
			printf('<p id="eum-auto-backup-description">%s %s</p>', esc_html($required_plugin), wp_kses($anchor, array('a' => array('href' => array()))));
		}
	}
}
printf('<p class="submit"><input type="submit" name="submit" id="force-updates" class="button button-primary" value="%s"></p>', esc_attr__('Force updates', 'stops-core-theme-and-plugin-updates'));
echo '</div>';
