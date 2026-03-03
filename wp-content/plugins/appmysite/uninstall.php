<?php

/**
 * Fired when the plugin is uninstalled.
 *
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
//load
require_once __DIR__ . '/vendor/autoload.php';

try {
	$config_transformer = new WPConfigTransformer( get_config_path() );
	
	if ( $config_transformer->exists( 'constant', 'AMS_LICENSE_KEY' ) ) {
		// update constant
		$config_transformer->remove( 'constant', 'AMS_LICENSE_KEY', array( 'normalize' => true ) ); //'raw' => true
		$config_transformer->remove( 'constant', 'AMS_LICENSE_STATUS', array( 'normalize' => true ) );
		
	}			
	
} catch ( \Exception $e ) {
	$messsage = 'Caught Exception: \Fragen\WP_Debugging\Settings::add_constants() - ' . $e->getMessage();
	// error_log( $messsage );
	wp_die( esc_html( $messsage ) );
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
