<?php
 /**
 * Plugin info.
 * @package    AppMySite
 * @author     AppMySite <support@appmysite.com>
 * @copyright  Copyright (c) 2023 - 2024, AppMySite
 * @link       https://appmysite.com
 * @license    http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
 
if ( !class_exists( 'AMS_Admin_Scripts' ) ) {
		
	final class AMS_Admin_Scripts{
		
		/**
		 * AMS_Admin_Scripts Constructor.
		 **/
		
		public function __construct() {
			add_action('admin_enqueue_scripts', array( &$this, 'ams_admin_enqueue_scripts') ); 
		}
		

		public function ams_admin_enqueue_scripts(){
					
			$current_page = get_current_screen()->base;
			
			if($current_page == 'plugins' || $current_page == 'plugins-network') {
				
				add_action('admin_footer', 'ams_deactivation_popup');
					
				//wp_register_script('ams_jquery', 'https://code.jquery.com/jquery-3.6.4.min.js', array(), '3.6.4', true); // jQuery v3
				//wp_enqueue_script('ams_jquery');
				//wp_script_add_data( 'ams_jquery', array( 'integrity', 'crossorigin' ) , array( 'sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=', 'anonymous' ) );
				
				wp_enqueue_script('ams_plugin_deactivation_survey_js', plugins_url('../assets/js/ams-plugin-deactivation-survey.js', __FILE__), array('jquery'));
				wp_localize_script('ams_plugin_deactivation_survey_js', 'frontend_ajax_object', array('amsDeactivationSurveyNonce' => wp_create_nonce('ajax-nonce')));
				wp_enqueue_style( 'ams_plugin_deactivation_survey_css', plugins_url('../assets/css/ams-plugin-deactivation-survey.css', __FILE__), array());
								
				
			} else { // # if not on plugins, deregister and dequeue styles & scripts

				//wp_dequeue_script('ams_jquery');
				wp_dequeue_script('ams_plugin_deactivation_survey_js');
				wp_dequeue_style('ams_plugin_deactivation_survey_css');

			}
			
			if($current_page == 'toplevel_page_ams-home'){
				//wp_register_script('ams_jquery', 'https://code.jquery.com/jquery-3.6.4.min.js', array(), '3.6.4', true); // jQuery v3
				//wp_enqueue_script('ams_jquery');
				//wp_script_add_data( 'ams_jquery', array( 'integrity', 'crossorigin' ) , array( 'sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=', 'anonymous' ) );
				
				wp_enqueue_script('ams_main_js', plugins_url('../assets/js/ams-main.js', __FILE__), array('jquery'));
				wp_localize_script('ams_main_js', 'frontend_ajax_object', array('amsFormNonce' => wp_create_nonce('ajax-nonce')));
				wp_enqueue_style( 'ams_main_css', plugins_url('../assets/css/ams-main.css', __FILE__), array());
				
			}else{
				//wp_dequeue_script('ams_jquery');
				wp_dequeue_script('ams_main_js');
			}
									
				
		}
		
		
			
	}

}

