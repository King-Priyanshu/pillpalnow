<?php if (!defined('EASY_UPDATES_MANAGER_MAIN_PATH')) die('No direct access allowed'); ?>

<div class="updraft-ad-container updated">
	<div class="updraft_notice_container">
		<div class="updraft_advert_content_left">
			<img src="<?php echo esc_url(EASY_UPDATES_MANAGER_URL.'images/'.$image); ?>" width="60" height="60" alt="<?php esc_attr_e('notice image', 'stops-core-theme-and-plugin-updates'); ?>" />
		</div>
		<div class="updraft_advert_content_right">
			<h3 class="updraft_advert_heading">
				<?php
				if (!empty($prefix)) echo esc_html($prefix).' ';
					echo esc_html($title);
				?>
				<div class="updraft-advert-dismiss">
				<?php if (!empty($dismiss_time)) { ?>
					<a href="#" onclick="jQuery('.updraft-ad-container').slideUp(); jQuery.post(ajaxurl, {action: 'easy_updates_manager_ajax', subaction: '<?php echo esc_js($dismiss_time); ?>', nonce: '<?php echo esc_js(wp_create_nonce('easy-updates-manager-ajax-nonce')); ?>' });"><?php esc_html_e('Dismiss', 'stops-core-theme-and-plugin-updates'); ?></a>
				<?php } else { ?>
					<a href="#" onclick="jQuery('.updraft-ad-container').slideUp();"><?php esc_html_e('Dismiss', 'stops-core-theme-and-plugin-updates'); ?></a>
				<?php } ?>
				</div>
			</h3>
			<p>
				<?php
				echo wp_kses_post($text);
					$button_text = '';
					if (isset($discount_code)) echo ' <b>' . esc_html($discount_code) . '</b>';

					if (!empty($button_link) && !empty($button_meta)) {
					// Check which Message is going to be used.
					if ('updraftcentral' == $button_meta) {
						$button_text = __('Get UpdraftCentral', 'stops-core-theme-and-plugin-updates');
					} elseif ('review' == $button_meta) {
						$button_text = __('Review "Easy Updates Manager"', 'stops-core-theme-and-plugin-updates');
					} elseif ('updraftplus' == $button_meta) {
						$button_text = __('Get UpdraftPlus', 'stops-core-theme-and-plugin-updates');
					} elseif ('signup' == $button_meta) {
						$button_text = __('Sign up', 'stops-core-theme-and-plugin-updates');
					} elseif ('go_there' == $button_meta) {
						$button_text = __('Go there', 'stops-core-theme-and-plugin-updates');
					} elseif ('wpo-premium' == $button_meta) {
						$button_text = __('Find out more.', 'stops-core-theme-and-plugin-updates');
					} elseif ('wp-optimize' == $button_meta) {
						$button_text = __('Get WP-Optimize', 'stops-core-theme-and-plugin-updates');
					} elseif ('aios' == $button_meta) {
						$button_text = __('Get AIOS', 'stops-core-theme-and-plugin-updates');
					} elseif ('eum_premium' == $button_meta) {
						$button_text = __('Get premium', 'stops-core-theme-and-plugin-updates');
					} elseif ('collection' == $button_meta) {
						$button_text = __('Read more', 'stops-core-theme-and-plugin-updates');
					}
					$easy_updates_manager->easy_updates_manager_url($button_link, $button_text, null, 'class="updraft_notice_link"');
					}
				?>
			</p>
		</div>
	</div>
	<div class="clear"></div>
</div>
