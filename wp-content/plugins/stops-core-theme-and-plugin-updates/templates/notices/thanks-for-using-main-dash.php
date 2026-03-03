<?php if (!defined('EASY_UPDATES_MANAGER_MAIN_PATH')) die('No direct access allowed'); ?>

<div id="easy-updates-manager-dashnotice" class="updated">
	<?php
	global $pagenow;
	if ('index.php' === $pagenow && !isset($_GET['page'])) {
		$ajax_action = 'dismiss_dash_notice_until';
		$time = 12;
	} else {
		$ajax_action = 'dismiss_eum_notice_until';
		$time = 6;
	}
	?>
	<div style="float:right;"><a href="#" onclick="jQuery('#easy-updates-manager-dashnotice').slideUp(); jQuery.post(ajaxurl, {action: 'easy_updates_manager_ajax', subaction: '<?php echo esc_js($ajax_action); ?>', nonce: '<?php echo esc_js(wp_create_nonce('easy-updates-manager-ajax-nonce')); ?>' });"><?php printf(esc_html__('Dismiss (for %s months)', 'stops-core-theme-and-plugin-updates'), absint($time)); ?></a></div>

	<h3><?php esc_html_e("Thank you for installing Easy Updates Manager.", 'stops-core-theme-and-plugin-updates'); ?></h3>
	<div id="easy-updates-manager-dashnotice-wrapper">
		<a href="<?php echo esc_url(apply_filters('easy_updates_manager_com_link', 'https://easyupdatesmanager.com/')); ?>"><img style="border: 0px; float: right; height: 130px; padding-bottom: 25px;" alt="<?php esc_attr_e('Easy Updates Manager', 'stops-core-theme-and-plugin-updates'); ?>" src="<?php echo esc_url(EASY_UPDATES_MANAGER_URL.'images/notices/eum-notice-logo-150.png'); ?>"></a>
		<p>
			<?php echo esc_html__('Super-charge and secure your WordPress site with our other top plugins:', 'stops-core-theme-and-plugin-updates'); ?>
		</p>
		<p>
			<?php printf(esc_html__('%s simplifies backups and restoration.', 'stops-core-theme-and-plugin-updates').' '.esc_html__('It is the world’s highest ranking and most popular scheduled backup plugin, with over a million currently-active installs.', 'stops-core-theme-and-plugin-updates'), '<strong>' . wp_kses($easy_updates_manager->easy_updates_manager_url('https://teamupdraft.com/updraftplus/', 'UpdraftPlus', '', '', true), array('a' => array('href' => array(), 'class' => array()))) . '</strong>'); ?>
		</p>

		<p>
			<?php printf(esc_html__('%s is a highly efficient way to manage, optimize, update and backup multiple websites from one place.', 'stops-core-theme-and-plugin-updates'), '<strong>' . wp_kses($easy_updates_manager->easy_updates_manager_url('https://teamupdraft.com/updraftcentral/?utm_source=eum-plugin&utm_medium=referral&utm_campaign=paac&utm_content=updraftcentral&utm_creative_format=advert', 'UpdraftCentral', '', '', true), array('a' => array('href' => array(), 'class' => array()))) . '</strong>'); ?>
		</p>

		<p>
			<?php printf(esc_html__('%s makes your site fast and efficient.  It cleans the database, compresses images and caches pages for ultimate speed.', 'stops-core-theme-and-plugin-updates'), '<strong>' . wp_kses($easy_updates_manager->easy_updates_manager_url('https://teamupdraft.com/wp-optimize/', __('WP-Optimize', 'stops-core-theme-and-plugin-updates'), '', '', true), array('a' => array('href' => array(), 'class' => array()))) . '</strong>'); ?>
		</p>

		<p>
			<strong><?php $easy_updates_manager->easy_updates_manager_url('https://www.simbahosting.co.uk/s3/shop/', __('Premium WooCommerce extensions', 'stops-core-theme-and-plugin-updates')); ?></strong>
		</p>

	</div>
</div>
