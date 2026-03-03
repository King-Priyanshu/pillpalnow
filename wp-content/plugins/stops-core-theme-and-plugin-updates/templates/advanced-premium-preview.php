<?php if (!defined('ABSPATH')) die('No direct access.'); ?>
<div class="eum-advanced-settings-container advanced-premium-preview_<?php echo esc_attr($key); ?>">
	<h3><?php echo esc_html($item['label']); ?></h3>

	<p><?php echo esc_html($item['desc']); ?></p>

	<div class="premium-only-desc">
		<h4 class="mpsum-medium">
			<span class="eum-advanced-menu-premium-only"><?php esc_html_e('Premium', 'stops-core-theme-and-plugin-updates'); ?></span>
			<?php esc_html_e('Interested in this feature?', 'stops-core-theme-and-plugin-updates'); ?>
		</h4>

		<p><?php

			$easy_updates_manager_url = 'https://easyupdatesmanager.com/buy/?utm=eum-advanced-tab';

			esc_html_e('Get many more features with Easy Updates Manager Premium.', 'stops-core-theme-and-plugin-updates');
			
			/* translators: 1: Link to the feature list, 2: Link to the store. */
			printf(' '.esc_html_x('Check out the video and feature list %1$s, or %2$s', 'Full text is: "Check out the video and feature list here, or go to our store", but with links added.', 'stops-core-theme-and-plugin-updates'), '<a href="'.esc_url(add_query_arg(array( 'tab' => 'premium' ), MPSUM_Admin::get_url())).'">'.esc_html_x('here', ' is included in the sentence: Check out the video and feature list here, or go to our store', 'stops-core-theme-and-plugin-updates').'</a>', '<a href="' . esc_url($easy_updates_manager_url) . '">'.esc_html_x('go to our store.', ' is included in the sentence: Check out the video and feature list here, or go to our store', 'stops-core-theme-and-plugin-updates').'</a>');
			
		?></p>
	</div>
</div>