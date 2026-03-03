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
 
if ( !class_exists( 'AMS_Register_Rest_Fields' ) ) {
		
	final class AMS_Register_Rest_Fields{
		
		/**
		 * AMS_Register_Rest_Fields Constructor.
		 **/
		
		function __construct() {
			
			$this->ams_register_rest_fields();
			
		}
		
		private function ams_register_rest_fields(){
			
			add_action('rest_api_init', function ()
			{		
				register_setting('general', 'ams_users_can_register', array(
				 'show_in_rest' => true,
				 'type' => 'boolean',
				 'default' => get_option( 'users_can_register' )
				));
			});
			
			register_rest_field(
				'post',
				'featured_image_src',
				array(
					'get_callback'    => array($this,'ams_ls_get_featured_media'),
					'update_callback' => null,
					'schema'          => null,
				)
			);
			
			register_rest_field(
				'post',
				'blog_images',
				array(
					'get_callback'    => array($this,'ams_ls_get_images_urls'),
					'update_callback' => null,
					'schema'          => null,
				)
			);
			
			register_rest_field(
				'product',
				'ams_default_variation_id',
				array(
					'get_callback'    => array($this,'ams_ls_get_default_variant'),
					'update_callback' => null,
					'schema'          => null,
				)
			);
			
			register_rest_field(
				['product','product_variation'],
				'ams_product_points_reward',
				array(
					'get_callback'    => array($this,'ams_ls_get_product_points_reward'),
					'update_callback' => null,
					'schema'          => null,
				)
			);
			
			register_rest_field(
				'product',
				'ams_product_discount_percentage',
				array(
					'get_callback'    => array($this,'ams_ls_get_product_discount_percentage'),
					'update_callback' => null,
					'schema'          => null,
				)
			);
			
			register_rest_field(
				['product','product_variation'],
				'ams_price_to_display',
				array(
					'get_callback'    => array($this,'ams_ls_get_product_price_to_display'),
					'update_callback' => null,
					'schema'          => null,
				)
			);
			
			add_action( 'rest_api_init', array($this,'ams_ls_register_rest_field_for_custom_post_type' ));
			
			add_action( 'rest_api_init', array($this,'ams_ls_add_acf_fields' ));
			
			add_action( 'rest_api_init', array($this,'ams_ls_create_api_customer_field' ));
			
			
		}
		
		public function ams_ls_get_featured_media( $object, $field_name, $request ) {
				$feat_img_array = wp_get_attachment_image_src(
					$object['featured_media'], // Image attachment ID
					'large',  // Size.  Ex. "thumbnail", "large", "full", etc..
					false // Whether the image should be treated as an icon.
				);
				if(isset($feat_img_array[0]) && !is_bool($feat_img_array[0])){
					return $feat_img_array[0];
				}else{
					return "";
				}
		}
			
		public function ams_ls_get_images_urls( $object, $field_name, $request ) {
				$medium     = wp_get_attachment_image_src( get_post_thumbnail_id( $object['id'] ), 'medium' );
				if(isset($medium['0'])&& !is_bool($medium[0])){$medium_url = $medium['0'];}else{$medium_url="";}
				$large     = wp_get_attachment_image_src( get_post_thumbnail_id( $object['id'] ), 'large' );
				if(isset($large['0'])&& !is_bool($large[0])){$large_url = $large['0'];}else{$large_url ="";}

				return array(
					'medium' => $medium_url,
					'large'  => $large_url,
				);
		}

		public function ams_ls_get_default_variant( $object, $field_name, $request ) {

				$product = wc_get_product( $object['id'] );
				if ( $product->is_type( 'variable' ) ) {
					$default_attributes = $product->get_default_attributes();
					if ( ! empty( $default_attributes ) ) {
						foreach ( $product->get_available_variations() as $variation_values ) {
							foreach ( $variation_values['attributes'] as $key => $attribute_value ) {
								$attribute_name = str_replace( 'attribute_', '', $key );
								$default_value  = $product->get_variation_default_attribute( $attribute_name );
								if ( $default_value == $attribute_value ) {
									$is_default_variation = true;
								} else {
									$is_default_variation = false;
									break;
								}
							}
							if ( $is_default_variation ) {
								$variation_id = $variation_values['variation_id'];
								break;
							}
						}
						return $variation_id;
					} else {
						return 0;
					}
				} else {
					return 0;
				}

			}

		public function ams_ls_get_product_points_reward( $object, $field_name, $request ) {

				$product = wc_get_product( $object['id'] );
				$product_id = $object['id'];
				
				if ( ! is_admin() && ! $product->is_type( 'variable' ) ) {			
					$_wc_max_points_earned = get_post_meta( $product_id, '_wc_max_points_earned' );
					$_wc_min_points_earned = get_post_meta( $product_id, '_wc_min_points_earned' );
					return get_post_meta( $product_id, '_wc_min_points_earned' );
					
				} else {
					return get_post_meta( $product_id, '_wc_points_earned' );
				}

			}

		public function ams_ls_get_product_discount_percentage( $object, $field_name, $request ) {

			$product = wc_get_product( $object['id'] );
			if ( $product->is_on_sale() && ! is_admin() && ! $product->is_type( 'variable' ) ) {
				$regular_price       = (float) $product->get_regular_price(); // Regular price
				$sale_price          = (float) $product->get_price();
				$saving_price        = wc_price( $regular_price - $sale_price );
				$precision           = 2;
				if($regular_price==0){return 0.00;}
				$discount_percentage = round( 100 - ( $sale_price / $regular_price * 100 ), 2 );
				return $discount_percentage;
			} else {
				return 0.00;
			}

		}
		
		public function ams_ls_get_product_price_to_display( $object, $field_name, $request ) {

				$product = wc_get_product( $object['id'] );
				$ams_price_to_display = wc_get_price_to_display($product,[]);
				return $ams_price_to_display;
				
		}
		
		public function ams_ls_register_rest_field_for_custom_post_type(){
			$ams_custom_post_types = array_values(get_post_types(array('_builtin' => false),'names','and'));	//'public' => true,'exclude_from_search' => false
			if (($key = array_search('product', $ams_custom_post_types)) !== false) { // We don't need this field for products
				unset($ams_custom_post_types[$key]);
			}
			register_rest_field(
			$ams_custom_post_types, //['product','course','projects']
			'featured_image_src',
			array(
				'get_callback'    => array($this,'ams_ls_get_image_src'),
				'update_callback' => null,
				'schema'          => null,
				)
			);
		}
			
		public function ams_ls_add_acf_fields() {		
			$postypes_to_exclude = ['acf-field-group','acf-field'];
			$extra_postypes_to_include = ["page","post"];
			$post_types = array_diff(get_post_types(["_builtin" => false], 'names'),$postypes_to_exclude);

			array_push($post_types, $extra_postypes_to_include);

			foreach ($post_types as $post_type) {
				register_rest_field( $post_type, 'ams_acf', [
					'get_callback'    => array($this,'ams_ls_expose_acf_fields'),
					'schema'          => null,
			   ]
			 );
			}
		}
				
		public function ams_ls_create_api_customer_field() {

			// register_rest_field ( 'name-of-post-type', 'name-of-field-to-return', array-of-callbacks-and-schema() )
			register_rest_field(
				'customer',
				'ams-rewards-points-balance',
				array(
					'get_callback' => array($this,'ams_ls_get_rewards_points_balance'),
					'schema'       => null,
				)
			);
		}
		
		public function ams_ls_get_image_src( $object, $field_name, $request ) {
			if(isset($object['featured_media'])){
				$feat_img_array = wp_get_attachment_image_src(
					$object['featured_media'], // Image attachment ID
					'large',  // Size.  Ex. "thumbnail", "large", "full", etc..
					false // Whether the image should be treated as an icon.
				);
				if(!$feat_img_array){return "";}
				return $feat_img_array[0];
			}
			return ""; //this will change the data type. 
		}
		
		public function ams_ls_get_rewards_points_balance( $object ) {
			if ( in_array(
				'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php',
				apply_filters( 'active_plugins', get_option( 'active_plugins' ) )
			)
			) {
				// get the value of the user's point balance
				$available_user_discount = WC_Points_Rewards_Manager::get_users_points( $object['id'] );
				// return the post meta
				return (float) $available_user_discount;
			} else {
				return 0;
			}
		}
		
		public function ams_ls_expose_acf_fields( $object ) {
			$ID = $object['id'];
			$final_fields = [];
			if(function_exists('get_field_objects')){
				$fields = get_field_objects($ID);
				$count = 0 ;
				if(!empty($fields)){
					foreach($fields as $key=>$value){
						
						$final_fields[$count]['key']= $key;					
						$final_fields[$count]['label'] = $value['label']  ; 
						$final_fields[$count]['value'] = $value['value'];
						$count++;
					}
				}
			}		
			return $final_fields;
		}
		
	}

}

