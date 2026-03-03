<?php
/**
 * Plugin Name: AppMySite
 * Plugin URI: https://www.appmysite.com
 * Description: This plugin enables WordPress & WooCommerce users to sync their websites with native iOS and Android apps, created on <a href="https://www.appmysite.com/"><strong>www.appmysite.com</strong></a>
 * Version: 3.15.2
 * Author: AppMySite
 * Text Domain: appmysite
 * Author URI: https://www.appmysite.com
 * Tested up to: 6.9
 * WC tested up to: 10.4.3
 * WC requires at least: 7.4
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 **/

	// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die( 'No script kiddies please!' );
}

if ( ! defined( 'AMS_PLUGIN_DIR' ) ) {
	define( 'AMS_PLUGIN_DIR', __FILE__ );
}

	/*******************************************************************************
	 * Show warning to all where WordPress version is below minimum requirement.
	 */

	global $wp_version;
	if ( $wp_version <= 4.9 ) {
		function wo_incompatibility_with_wp_version() {
			?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'AppMySite requires that WordPress 4.9 or greater be used. Update to the latest WordPress version.', 'appmysite' ); ?>
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Update Now', 'appmysite' ); ?></a></p>
				</div>
				<?php
		}

		add_action( 'admin_notices', 'wo_incompatibility_with_wp_version' );
	}
	
	register_activation_hook(__FILE__, 'ams_activate');
	register_deactivation_hook(__FILE__, 'ams_deactivate');
			
	//load
	require_once __DIR__ . '/vendor/autoload.php';
	// Include the main AMS_Rest_Routes class.
	if ( ! class_exists( 'AMS_Rest_Routes', false ) ) {
		include_once dirname( AMS_PLUGIN_DIR ) . '/includes/class-ams-rest-routes.php';
		new AMS_Rest_Routes();
	}

	// Include the main AMS_Register_Rest_Fields class.
	if ( ! class_exists( 'AMS_Register_Rest_Fields', false ) ) {
		include_once dirname( AMS_PLUGIN_DIR ) . '/includes/class-ams-rest-register-fields.php';
		new AMS_Register_Rest_Fields();
	}
	
	// Include the main AMS_Filters class.
	if ( ! class_exists( 'AMS_Filters', false ) ) {
		include_once dirname( AMS_PLUGIN_DIR ) . '/includes/class-ams-filters.php';
		new AMS_Filters();
	}
			 			

	// Include the main AMS_Admin_Functions class.
	if ( ! class_exists( 'AMS_Admin_Functions', false ) ) {
		include_once dirname( AMS_PLUGIN_DIR ) . '/includes/class-ams-admin-functions.php';
		new AMS_Admin_Functions();
	}
	
	
	// Include the main AMS_Admin_Scripts class.
	if ( ! class_exists( 'AMS_Admin_Scripts', false ) ) {
		include_once dirname( AMS_PLUGIN_DIR ) . '/includes/class-ams-admin-scripts.php';
		new AMS_Admin_Scripts();
	}
	
	add_action( 'before_woocommerce_init', function() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	} );
	
	function ams_activate()
	{
		// This plugin works by the somewhat hidden feature of WordPress called MU-plugins (that's short for 'must use', not the old abbreviation for multisite).
		// So basically we'll make sure the folder exists and copy a file to that folder.
		
		/*
		if(!file_exists(WP_CONTENT_DIR . '/mu-plugins/'))
			@mkdir(WP_CONTENT_DIR . '/mu-plugins/');
	
		if(file_exists(WP_CONTENT_DIR . '/mu-plugins/safe-mode-loader.php'))
			@unlink(WP_CONTENT_DIR . '/mu-plugins/safe-mode-loader.php');
			
		if(file_exists(WP_PLUGIN_DIR . '/' . plugin_basename(dirname(__FILE__)) . '/includes/safe-mode-loader.php'))
			@copy(WP_PLUGIN_DIR . '/' . plugin_basename(dirname(__FILE__)) . '/includes/safe-mode-loader.php', WP_CONTENT_DIR . '/mu-plugins/safe-mode-loader.php');
		*/
		try {
			$config_transformer = new WPConfigTransformer( get_config_path() );
			//initialize AMS_SAFE_MODE with default off.
			$config_transformer->update( 'constant', 'AMS_SAFE_MODE', "off", array( 'normalize' => true ) ); //'raw' => true			
			
		} catch ( \Exception $e ) {
			$messsage = 'Unable to update AMS_SAFE_MODE in wp-config. ' . $e->getMessage();
			wp_die( esc_html( $messsage ) );
		}
		
	}
	
	function ams_deactivate()
	{
		try {
			$config_transformer = new WPConfigTransformer( get_config_path() );
			
			if ( $config_transformer->exists( 'constant', 'AMS_SAFE_MODE' ) ) {
				// update constant
				$config_transformer->remove( 'constant', 'AMS_SAFE_MODE', array( 'normalize' => true ) ); //'raw' => true				
			}			
			
		} catch ( \Exception $e ) {
			$messsage = 'Unable to update AMS_SAFE_MODE in wp-config. - ' . $e->getMessage();
			wp_die( esc_html( $messsage ) );
		}
		if(file_exists(WP_CONTENT_DIR . '/mu-plugins/safe-mode-loader.php'))
			@unlink(WP_CONTENT_DIR . '/mu-plugins/safe-mode-loader.php');		
		
	}
	
	function get_config_path() {
		$config_path = ABSPATH . 'wp-config.php';

		if ( ! file_exists( $config_path ) ) {
			if ( @file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! @file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
				$config_path = dirname( ABSPATH ) . '/wp-config.php';
			}
		}

		return apply_filters( 'wp_debugging_config_path', $config_path );
	}
			


