<?php
/**
 * Help Screen for Easy Updates Manager
 * Initializes and outputs the help screen for the plugin.
 *
 * @package WordPress
 * @since 5.0.0
 */
class MPSUM_Admin_Help {

	/**
	 * Class constructor.
	 *
	 * Initialize the class
	 *
	 * @since 5.0.0
	 * @access public
	 */
	public function __construct() {
		$screen = get_current_screen();

		$content1_strings = array(
			'website' => __('Our website', 'stops-core-theme-and-plugin-updates'),
			'donate' => __('Donate', 'stops-core-theme-and-plugin-updates'),
			'support' => __('Support on WordPress', 'stops-core-theme-and-plugin-updates'),
			'premium_support' => __('Premium support', 'stops-core-theme-and-plugin-updates'),
			'official' => __('Documentation', 'stops-core-theme-and-plugin-updates'),
		);
		$content1  = '<p>';
		$content1 .= '<a href="https://easyupdatesmanager.com" class="button">' . esc_html($content1_strings['website']) . '</a> ';
		$content1 .= '<a href="http://wordpress.org/support/plugin/stops-core-theme-and-plugin-updates" class="button">' . esc_html($content1_strings['support']) . '</a> ';
		$content1 .= '<a href="https://easyupdatesmanager.com/support/" class="button">' . esc_html($content1_strings['premium_support']) . '</a> ';
		$content1 .= '<a href="https://easyupdatesmanager.com/documentation/" class="button">' . esc_html($content1_strings['official']) . '</a>';
		$content1 .= '</p>';
		$content1 .= '<p>';
		$content1 .= esc_html__('This is the Easy Updates Manager settings help tab.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('Here you will find helpful information on what Easy Updates Manager does and how to use it.', 'stops-core-theme-and-plugin-updates');
		$content1 .= '</p>';
		$content1 .= sprintf('<div><p><strong>%s - </strong>%s</p></div>', esc_html__('Please note!', 'stops-core-theme-and-plugin-updates'), esc_html__('If either your WordPress core, theme, or plugins get too out of date, you may run into compatibility problems.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('Check the capability tab for more information.', 'stops-core-theme-and-plugin-updates'));

		$content2 = sprintf('<div><p><a href="https://easyupdatesmanager.com/documentation/">%s</a></p></div>', esc_html__('Check out our documentation for updated documentation and videos.', 'stops-core-theme-and-plugin-updates'));

		$content4_strings = array(
			'intro' => __('You will see multiple tabs where you can configure the update options.', 'stops-core-theme-and-plugin-updates'),
			'general' => sprintf('<strong>%s</strong> - %s', __('General', 'stops-core-theme-and-plugin-updates'), __('Use this screen to finely tune which updates and automatic updates you would like to see.', 'stops-core-theme-and-plugin-updates')),
			'plugins' => sprintf('<strong>%s</strong> - %s', __('Plugins', 'stops-core-theme-and-plugin-updates'), __('If plugin updates are enabled and/or automatic updates for plugins are enabled, you can configure which plugins will receive updates and/or automatic updates.', 'stops-core-theme-and-plugin-updates')),
			'themes' => sprintf('<strong>%s</strong> - %s', __('Themes', 'stops-core-theme-and-plugin-updates'), __('If theme updates are enabled and/or automatic updates for themes are enabled, you can configure which themes will receive updates and/or automatic updates.', 'stops-core-theme-and-plugin-updates')),
			'logs' => sprintf('<strong>%s</strong> - %s', __('Logs', 'stops-core-theme-and-plugin-updates'), __('Logs all plugin, theme, and core updates.', 'stops-core-theme-and-plugin-updates').' '.__('This tab is visible by default.', 'stops-core-theme-and-plugin-updates')),
			'advanced' => sprintf('<strong>%s</strong> - %s', __('Advanced', 'stops-core-theme-and-plugin-updates'), __('Reset all options or allow certain users to see all updates regardless of what settings you have set.', 'stops-core-theme-and-plugin-updates')),

		);
		$content4_allowed_tags = array('p' => array(),'strong' => array(),'br' => array());
		$content4 = wp_kses('<p>' . $content4_strings['intro'] . '<br><br>' . $content4_strings['general'] . '<br><br>' . $content4_strings['plugins'] . '<br><br>' . $content4_strings['themes'] . '<br><br>' . $content4_strings['logs'] . '<br><br>' . $content4_strings['advanced'] . '</p>', $content4_allowed_tags);

		$content6 = '<p>';
		$content6 .= esc_html__('WordPress encourages you to update your plugins, themes, and core to make sure that there are no bugs.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('Even though you most likely want to disable all the updates and never think about updating again, you should still consider updating every once in a while to avoid major bugs and errors on your WordPress website.', 'stops-core-theme-and-plugin-updates');
		$content6 .= sprintf('<h4>%s</h4>', esc_html__('This plugin is tested with the most recent versions of WordPress to ensure that there are no major issues.', 'stops-core-theme-and-plugin-updates'));
		$content6 .= '</p>';

		$screen->add_help_tab(array(
			'id'      => 'help_tab_content_1',
			'title'   => __('Overview',  'stops-core-theme-and-plugin-updates'),
			'content' => $content1,
		));

		$screen->add_help_tab(array(
			'id' => 'help_tab_content_4',
			'title' => __('Navigation',  'stops-core-theme-and-plugin-updates'),
			'content' => $content4,
		));

		$screen->add_help_tab(array(
			'id' => 'help_tab_content_2',
			'title' => __('Documentation',  'stops-core-theme-and-plugin-updates'),
			'content' => wpautop($content2),
		));

		$screen->add_help_tab(array(
			'id' => 'help_tab_content_6',
			'title' => __('Capability',  'stops-core-theme-and-plugin-updates'),
			'content' => wpautop($content6),
		));

	} //end constructor
}
