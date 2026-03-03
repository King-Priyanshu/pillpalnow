<?php
/**
 * @AppMySite
 *
 * For REST API Manipulation
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once(ABSPATH . 'wp-admin/includes/plugin.php');
 
if ( !class_exists( 'AMS_Rest_Routes' ) ) {
		
	final class AMS_Rest_Routes{
		
		/**
		 * AMS_Rest Constructor.
		 **/
		
		public function __construct() {
			$this->ams_register_routes();
		}
		
			
		private function ams_register_routes() {
			
			########### Provision for safe mode in REST APIs###########
			
			add_action(
				'rest_api_init',
				function () {
					if(defined('AMS_SAFE_MODE')){
						if(AMS_SAFE_MODE == 'on'){
							add_filter('option_active_plugins', array($this, 'ams_disable_plugins'), 10, 1);
							add_filter('template', array($this, 'ams_disable_theme'), 10, 1);
							add_filter('stylesheet', array($this, 'ams_disable_theme'), 10, 1);
						}
					}
			});
						
			########### Provision for safe mode End###########
			
			add_action(
				'rest_api_init',
				function () {

					// Registered custom user meta i.e. 'bookmark' here
					$this->register_custom_user_meta();

					// Add filter to modify the REST API response to include custom user meta
					add_filter('rest_prepare_user', array($this, 'add_custom_user_meta_to_rest'), 10, 3);
					
					register_rest_route(
						'wc/v3',
						'/ams-get-active-plugins',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_get_active_plugins'),
							'permission_callback' => array($this, 'ams_authorize_admin_only'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-get-plugin-info',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_get_version_info'),
							'permission_callback' => array($this, 'ams_authorize_admin_only'),
						)
					);	
					
					register_rest_route(
						'wc/v3',
						'/ams-menu',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_get_menu_items'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-menu-names',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_get_menu_names'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-login',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_ls_login'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-verify-user',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_ls_verify_user'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-profile-meta',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_ls_get_profile_meta'),
							'permission_callback' => array($this, 'ams_authorize_user_data_access'),
							'args' => array(
								'id' => array(
									'required' => true,
									'type' => 'integer',
									'description' => 'User ID',
								)
							),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-order-payment-url',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_ls_get_order_payment_url'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route('wc/v3', '/ams-verify-application-password', array(
					'methods' => 'GET',
					'callback' => array($this,'ams_ls_verify_application_password'),
					'permission_callback' => array($this, 'ams_authorize_admin_only'),
					));
					
					register_rest_route('wc/v3', '/ams-wp-get-user-auth-cookies', array(
					'methods' => 'POST',
					'callback' => array($this,'ams_ls_wp_get_user_auth_cookies'),
					'permission_callback' => array($this, 'ams_authorize_admin_only'),
					'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							)
						) ,
					));
					
					
					register_rest_route(
						'wc/v3',
						'/ams-send-password-reset-link',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_ls_send_password_reset_link'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-applicable-shipping-method',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_ls_applicable_shipping_method'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
							'args' => array(
								'customer_id' => array(
									'required' => false,
									'type' => 'integer',
									'description' => 'Customer ID',
								),
							),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-product-search',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_ls_product_search'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-product-attributes',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_ls_product_attributes'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-verify-cart-items',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_ls_verify_cart_items'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-categories',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_categories'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-post-categories',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_post_categories'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-checkout-fields',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_checkout_fields'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-wc-points-rewards-effective-discount',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_wc_points_rewards_effective_discount'),
							'permission_callback' => array($this, 'ams_authorize_user_data_access'),
							'args'     => array(
								'line_items'  => array(
									'required'    => true,
									'type'        => 'array',
									'description' => 'Cart items',
								),
								'customer_id' => array(
									'required'    => true,
									'type'        => 'integer',
									'description' => 'Customer ID',
								),
								'wc_points_rewards_discount_amount' => array(
									'required'    => true,
									'type'        => 'number',
									'description' => 'Requested user discount',
								),
							),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-wc-points-rewards-settings',
						array(
							'methods'  => 'GET',
							'callback' => array($this,'ams_wc_points_rewards_settings'),
							'permission_callback' => array($this, 'ams_authorize_authenticated'),
						)
					);
					
					register_rest_route(
						'wc/v3',
						'/ams-change-password',
						array(
							'methods'  => 'POST',
							'callback' => array($this,'ams_change_password'),
							'permission_callback' => array($this, 'ams_authorize_user_data_access'),
							'args' => array(
									
									'customer_id' => array(
										'required' => true,
										'type' => 'integer',
										'description' => 'Customer ID',
									),
									'old_password' => array(
										'required' => true,
										'type' => 'text',
										'description' => 'Old Password',
									),
									'new_password' => array(
										'required' => true,
										'type' => 'text',
										'description' => 'New Password',
									),
									'confirm_password' => array(
										'required' => true,
										'type' => 'text',
										'description' => 'New Password',
									),
								)
						)
					);
					
					register_rest_route('wc/v3', '/ams-user-wishlist', array(
						'methods' => 'GET',
						'callback' => array($this,'ams_ls_user_wishlist'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							)
						),
					));
					
					register_rest_route('wc/v3', '/ams-user-wishlist/add', array(
						'methods' => 'POST',
						'callback' => array($this,'ams_ls_user_wishlist_add'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							),
							'product_ids' => array(
								'required' => true,
								'type'     => 'array',
								'description' => 'Product ID',
							)
						),
					));
					
					register_rest_route('wc/v3', '/ams-user-wishlist/remove', array(
						'methods' => 'POST',
						'callback' => array($this,'ams_ls_user_wishlist_remove'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							),
							'product_ids' => array(
								'required' => true,
								'type'     => 'array',
								'description' => 'Product ID',
							)
						),
					));
					
					register_rest_route('wc/v3', '/ams-user-wishlist/clear', array(
						'methods' => 'POST',
						'callback' => array($this,'ams_ls_user_wishlist_clear'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							)
						),
					));

					register_rest_route('wc/v3', '/ams-user-bookmarks', array(
						'methods' => 'GET',
						'callback' => array($this,'ams_ls_user_bookmarks'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							)
						),
					));
						
					register_rest_route('wc/v3', '/ams-user-bookmarks/add', array(
						'methods' => 'POST',
						'callback' => array($this,'ams_ls_user_bookmarks_add'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							),
							'post_ids' => array(
								'required' => true,
								'type'     => 'array',
								'description' => 'Post ID',
							)
						),
					));
						
					register_rest_route('wc/v3', '/ams-user-bookmarks/remove', array(
						'methods' => 'POST',
						'callback' => array($this,'ams_ls_user_bookmarks_remove'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							),
							'post_ids' => array(
								'required' => true,
								'type'     => 'array',
								'description' => 'Post ID',
							)
						),
					));
						
					register_rest_route('wc/v3', '/ams-user-bookmarks/clear', array(
						'methods' => 'POST',
						'callback' => array($this,'ams_ls_user_bookmarks_clear'),
						'permission_callback' => array($this, 'ams_authorize_user_data_access'),
						'args' => array(
							'user_id' => array(
								'required' => true,
								'type' => 'integer',
								'description' => 'User ID',
							)
						),
					));
					
				}
			);
		}
		
		public function register_custom_user_meta() {
			// Register custom user meta key to be included in REST API
			register_meta('user', 'ams_bookmarks', [
				'type'         => 'array',
				'description'  => 'This is bookmared post ids of user',
				'single'       => false,
				'show_in_rest' => true, // Ensures it is available in the REST API
			]);
		}

				// Modify REST API response to include custom user meta
		public function add_custom_user_meta_to_rest($response, $user, $request) {
			// Get the custom meta value for the user
			$custom_meta = get_user_meta($user->ID, 'ams_bookmarks', true);
			
			// If meta exists, add it to the response
			if (!empty($custom_meta)) {
				$response->data['meta']['ams_bookmarks'] = $custom_meta;
			}

			return $response;
		}

		public	function ams_get_version_info( WP_REST_Request $request ){
				
				if ( ! function_exists( 'plugins_api' ) ) {
					require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );
				}
				
				if( ! function_exists( 'get_plugin_data' ) ) {
					require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
				}
				$version_current = get_plugin_data( __FILE__ )['Version'];
				
				$args = array(
					'slug' => 'appmysite',
					'fields' => array(
						'version' => true,
					)
				);

				$call_api = plugins_api( 'plugin_information', $args );

				if ( is_wp_error( $call_api ) ) {

					$api_error = $call_api->get_error_message();

					return( rest_ensure_response([
												"AMS_PLUGIN_LATEST_VERSION"=>"0.0.0",
												"AMS_PLUGIN_CURRENT_VERSION"=>"0.0.0"
												]));							
				} else {

					if ( ! empty( $call_api->version ) ) {

						$version_latest = $call_api->version;
						return( rest_ensure_response( ["AMS_PLUGIN_LATEST_VERSION"=>$version_latest,
													  "AMS_PLUGIN_CURRENT_VERSION"=>$version_current] ) );
					}

				}						
								
		}
		
		/******

		 * Get all items of given menu.
		 ******/

		public function ams_get_menu_items( WP_REST_Request $request ) {

			$menu_name = 'primary-menu'; // primary-menu, top

			if ( isset( $request['menu_name'] ) ) {
				$menu_name = $request['menu_name'];
			}
			$nav_menu_items     = wp_get_nav_menu_items( $menu_name );  //slug,id
			return( rest_ensure_response( $nav_menu_items ) );

		}
	
		/******

		 * Get all menu names.
		 ******/

		public function ams_get_menu_names() {

			$nav_menu_locations = wp_get_nav_menus();
			$result = [];
			foreach((array)$nav_menu_locations as $item){
				$result[$item->slug]=$item->term_id;
			}
			return( rest_ensure_response( $result ) );

		}
	
		public function ams_ls_verify_application_password( WP_REST_Request $request ) { 

			$user = get_user_by( 'ID', apply_filters('determine_current_user', false) ); // | User by ID 
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				$user->data->user_pass = '';
				$user->data->user_activation_key = '';
				$user->data->id = $user->ID; //integer
				$user->data->first_name = get_user_meta( $user->ID, 'first_name', true );
				$user->data->last_name = get_user_meta( $user->ID, 'last_name', true );			
				$user->data->roles = $user->roles;
				####get user wp_generate_auth_cookie####
				
				########################################
				return rest_ensure_response( $user->data );
			} else {
				return new WP_Error('ams_error', 'Something went wrong. Please contact support.', array('status' => 500));
			}
		}
		
		/******

		 * Get all product and post categories in a binary tree.
		******/
		public function ams_categories() {

			$orderby    = 'name';
			$order      = 'asc';
			$hide_empty = true;
			$cat_args   = array(
				'orderby'    => $orderby,
				'order'      => $order,
				'hide_empty' => $hide_empty,
			);

			$product_categories             = array_values( get_terms( 'product_cat', $cat_args ) );
			$array_product_categories_items = json_decode( json_encode( $product_categories ), true );
			if ( empty( $array_product_categories_items ) ) {
				return rest_ensure_response( $array_product_categories_items );
			}
			$category_tree = $this->ams_build_category_tree( $array_product_categories_items, 'parent', 'term_id' );
			return rest_ensure_response( $category_tree );

		}

		public function ams_post_categories() {

			$orderby    = 'name';
			$order      = 'asc';
			$hide_empty = true;
			$cat_args   = array(
				'orderby'    => $orderby,
				'order'      => $order,
				'hide_empty' => $hide_empty,
			);

			$product_categories             = array_values( get_terms( 'category', $cat_args ) );
			$array_product_categories_items = json_decode( json_encode( $product_categories ), true );
			if ( empty( $array_product_categories_items ) ) {
				return rest_ensure_response( $array_product_categories_items );
			}
			$category_tree = $this->ams_build_category_tree( $array_product_categories_items, 'parent', 'term_id' );
			return rest_ensure_response( $category_tree );

		}


		/******

		 * Cart items verification
		 ******/

		public function ams_ls_verify_cart_items( WP_REST_Request $request ) {

			$params   = $request->get_params();
			$validate = $this->ams_basic_validate( $params, array( 'line_items' ) );
			if ( $validate != true ) {
				return $validate;
			}

			$line_items = $params['line_items'];
			$result     = array();
			foreach ( $line_items as $key => $value ) {
				if ( array_key_exists( 'variation_id', $value ) ) {

					$variation = wc_get_product( $value['variation_id'] );

					if ( $variation ) {
						$result[ $key ]['product_id']     = $variation->get_id();
						$result[ $key ]['variation_id']   = $value['variation_id'];
						$result[ $key ]['name']           = $variation->get_name();
						$result[ $key ]['type']           = $variation->get_type();
						$result[ $key ]['status']         = $variation->get_status();
						$result[ $key ]['price']          = $variation->get_price();
						$result[ $key ]['regular_price']  = $variation->get_regular_price();
						$result[ $key ]['sale_price']     = $variation->get_sale_price();
						$result[ $key ]['manage_stock']   = $variation->get_manage_stock();
						$result[ $key ]['stock_quantity'] = $variation->get_stock_quantity();
						if ( $result[ $key ]['stock_quantity'] == null ) {
							$result[ $key ]['stock_quantity'] = '';
						}
						$result[ $key ]['stock_status'] = $variation->get_stock_status();
						$result[ $key ]['on_sale']      = $variation->is_on_sale();
						if ( 1 == $result[ $key ]['on_sale'] ) {

							$result[ $key ]['on_sale'] = true;
						} else {
							$result[ $key ]['on_sale'] = false;
						}
					}
				} else {
					// get product details
					$product = wc_get_product( $value['product_id'] );
					if ( $product ) {
						$result[ $key ]['product_id']        = $product->get_id();
						$result[ $key ]['name']        		 = $product->get_name();
						$result[ $key ]['type']              = $product->get_type();
						$result[ $key ]['status']            = $product->get_status();
						$result[ $key ]['get_price']         = $product->get_price();
						$result[ $key ]['get_regular_price'] = $product->get_regular_price();
						$result[ $key ]['get_sale_price']    = $product->get_sale_price();
						$result[ $key ]['manage_stock']      = $product->get_manage_stock();
						$result[ $key ]['stock_quantity']    = $product->get_stock_quantity();
						if ( $result[ $key ]['stock_quantity'] == null ) {
							$result[ $key ]['stock_quantity'] = '';
						}
						$result[ $key ]['stock_status'] = $product->get_stock_status();
						$result[ $key ]['on_sale']      = $product->is_on_sale();
						if ( 1 == $result[ $key ]['on_sale'] ) {
							$result[ $key ]['on_sale'] = true;
						} else {
							$result[ $key ]['on_sale'] = false;
						}
					}
				}
			}

			return rest_ensure_response( array( 'line_items' => $result ) );
		}

		/******

		 * Search API with support of multiple sort and order_by parameters .
		 ******/
		public function ams_ls_product_search( WP_REST_Request $request ) {

			$param        = $request->get_params();		
			$output       = array();
			// Use default arguments.
			$args = array(
				'post_type'      => 'product',
				'posts_per_page' => get_option( 'posts_per_page' ),
				'post_status'    => 'publish',
				'paged'          => 1,

			);
			// Posts per page.
			if ( ! empty( $param['per_page'] ) ) {
				$per_page = $param['per_page'];
				$args['posts_per_page'] = $param['per_page'];
			}
			// Pagination, starts from 1.
			if ( ! empty( $param['page'] ) ) {
				$args['paged'] = $param['page'];
			}

			// Order condition. ASC/DESC.
			if ( ! empty( $param['order'] ) ) {
				$order  = $param['order'];
				$args['order'] = $param['order'];
			}
			// Order condition. ASC/DESC.
			if ( ! empty( $param['orderby'] ) ) {
				$orderby  = $param['orderby'];
				if ( $orderby == 'price' ) {
					$args['orderby']  = 'meta_value_num';
					$args['meta_key'] = '_price';

				} elseif ( $orderby == 'popularity' ) {   // For Popularity case, the sort order will always be desc.
					$args['orderby']  = 'meta_value_num';
					$args['meta_key'] = 'total_sales';

				} else {
					$args['orderby'] = $orderby;
				}
			}

			if ( ! empty( $param['featured'] ) ) {
				if ( $param['featured'] == true ) {
					$args['tax_query'][] = array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => 'featured',
					);
				}
			}

			if ( ! empty( $param['on_sale'] ) ) {
				$on_sale  = $param['on_sale'];
				if($on_sale === 'true' || $on_sale === 'TRUE' || $on_sale === 'True' || $on_sale === 'on' || $on_sale === 'On' || $on_sale === 'ON'|| $on_sale === "1" || $on_sale === 1 ){
					$on_sale = true;
				}else{
					$on_sale = false;
				}		
				 if( is_bool( $on_sale ) ) { //|| $on_sale =='true' || $on_sale=='false'
					
					$on_sale_key = $on_sale ? 'post__in' : 'post__not_in';
					$on_sale_ids = wc_get_product_ids_on_sale();

					// Use 0 when there's no on sale products to avoid return all products.
					$on_sale_ids = empty( $on_sale_ids ) ? array( 0 ) : $on_sale_ids;
						
					$args[ $on_sale_key ] = $on_sale_ids;			
						
				}
			}
			
					
			
			if ( ! empty( $param['stock_status'] ) ) {
					$args['meta_query'][] = array(
						'key'   => '_stock_status',
						'value' => $param['stock_status'],
					);
			}
			
			if ( isset( $param['min_price'] ) || isset( $param['max_price'] ) ) {

				$price_request = array();
				if ( isset( $param['min_price'] ) ) {
					$price_request['min_price'] = $param['min_price'];
				}

				if ( isset( $param['max_price'] ) ) {
					$price_request['max_price'] = $param['max_price'];
				}
				$args['meta_query'][] = wc_get_min_max_price_meta_query( $price_request );
			}

			if ( ! empty( $param['category'] ) || ! empty( $param['filter'] ) || !empty( $param['tag'] ) ) {

				$args['tax_query']['relation'] = 'AND';
				if ( ! empty( $param['category'] ) ) {
					$args['tax_query'][] = array(
						'taxonomy' => 'product_cat',
						'field'    => 'id',
						'terms'    => explode( ',', $param['category'] ),        // [ $category ],
					);
				}
				
				if ( ! empty( $param['tag'] ) ) {
					$args['tax_query'][] = array(
						'taxonomy' => 'product_tag',
						'field'    => 'id',
						'terms'    => explode( ',', $param['tag'] ),        // [ $tag ],
					);
				}

				if ( ! empty( $param['filter'] ) ) {
					foreach ( $param['filter'] as $filter_key => $filter_value ) {
						if ( $filter_key === 'min_price' || $filter_key === 'max_price' ) {
							continue;
						}
						$args['tax_query'][] = array(
							'taxonomy' => $filter_key,
							'field'    => 'term_id',
							'terms'    => explode( ',', $filter_value ),
						);
					}
				}
			}

			$the_query = new \WP_Query( $args );

			if ( ! $the_query->have_posts() ) {
				return $output;
			}
			while ( $the_query->have_posts() ) {
				$the_query->the_post();
				$product_ids[] = $the_query->post->ID;
				$output[]      = get_the_title();
			}
			wp_reset_postdata();

			$request    = new WP_REST_Request( 'GET', '/wc/v3/products' );
			$parameters = array( 'include' => $product_ids );
			if ( ! empty( $order ) ) {
				$parameters += array( 'order' => $order );
			}
			if ( ! empty( $orderby ) ) {
				$parameters += array( 'orderby' => $orderby );
			}
			if ( ! empty( $per_page ) ) {
				$parameters += array( 'per_page' => $per_page );
			}
			$request->set_query_params( $parameters );
			$response = rest_do_request( $request );
			$server   = rest_get_server();
			$data     = $server->response_to_data( $response, false );
			return rest_ensure_response( $data );
		}


		public function ams_ls_product_attributes( WP_REST_Request $request ) {

			$param  = $request->get_params();
			
			$args = array(
				'status'    => 'publish',
				'limit' => -1
			);
			
			if(isset( $param['category'] )){
				$cat_name = get_term($param['category'], 'product_cat', ARRAY_A ); 
			
				if(is_wp_error( $cat_name) ){
					status_header( 400 );
					echo ( json_encode(
						array(
							'message' => 'There is a problem with your input.',
							'error'   => $cat_name->get_error_message(),
						),
						JSON_UNESCAPED_UNICODE
					) );
					die();		
				}
				$args['category'] = array($cat_name['slug'] );
			}
			
			if(isset( $param['stock_status'] )){
				$args['stock_status'] = $param['stock_status'];
			}
			
			if(isset( $param['featured'] )){
				$args['featured'] = $param['featured'];			
			}
			
			if(isset( $param['on_sale'] )){
				$args['on_sale'] = $param['on_sale'];
			}
			
			if(isset( $param['tag'] )){
				$args['tag'] = $param['tag'];
			}
			
			$result = [];
			
			$filter_raw = array(); 
			$attrs_raw  = wc_get_attribute_taxonomy_names(); 
			foreach( wc_get_products($args) as $product ){
				foreach( $product->get_attributes() as $attr_name => $attr ){
				$filter_raw[] = $attr_name;
				if(is_array($attr->get_terms())){    
					foreach( $attr->get_terms() as $term ){
						$terms_raw[] = $term->name;
					}
				}
				}
			}
			$filters = array_unique(array_intersect((array)$filter_raw,(array)$attrs_raw)); 
			
			if(is_array($filters)){    
				foreach ( $filters as $key=>$filter ){
					$terms = get_terms( $filter );
					if ( ! empty( $terms ) ) {

						$result[$key] = array(
							'id'    => $filter,
							'label' =>  wc_attribute_label( $filter ) , //$this->decode_html
						);
						foreach ( $terms as $term ) {
							if(in_array($term->name,$terms_raw)){
							$result[$key]['values'][] = array(
								'label' =>  $term->name ,
								'value' => $term->slug,
								'term_id' => $term->term_id,
								'count' => $term->count,							
							);
							}
						}
					}
				}
			}
			
			return( rest_ensure_response( array_values($result )) );

		}
		
		/******

		 * Authenticate the user.
		 ******/

		public function ams_ls_login( WP_REST_Request $request ) {

			$req = $request->get_json_params();

			$validate = $this->ams_basic_validate( $req, array( 'username', 'password' ) );
			if ( $validate != true ) {
				return $validate;
			}
				$wp_version = get_bloginfo( 'version' );
				$user       = wp_authenticate( sanitize_text_field( $req['username'] ), sanitize_text_field( $req['password'] ) );  // htmlspecialchars

			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				$user->data->user_pass  = '';
				$user->data->user_activation_key = '';
				$user->data->id = $user->ID; //integer
				$user->data->first_name = get_user_meta( $user->ID, 'first_name', true );
				$user->data->last_name = get_user_meta( $user->ID, 'last_name', true );						
				$user->data->roles = $user->roles;			
				$user->data->wp_version = $wp_version;
				return rest_ensure_response( $user->data );
			} else {
				return new WP_Error('ams_error', 'Something went wrong. Please contact support.', array('status' => 500));
			}
		}


		/******

		 * Verify the user.
		 ******/
		public function ams_ls_verify_user( WP_REST_Request $request ) {

			$req = $request->get_json_params();

			$validate = $this->ams_basic_validate( $req, array( 'username' ) );
			if ( $validate != true ) {
				return $validate;
			}

			$is_email = is_email($req['username']);
			if(!$is_email){
				$user = get_user_by( 'login', $req['username'] ); // | ID | slug | email | login.
			}else{			
				$user = get_user_by( 'email', $req['username'] ); // | ID | slug | email | login.
				if( isset( $user->errors ) ) { 
					$user = get_user_by( 'login', $req['username'] ); // | ID | slug | email | login.
				}
			}
			
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				
				$user_data = (object)[];
				$user_data->ID=$user->data->ID;
				$user_data->user_nicename=$user->data->user_nicename;
				$user_data->display_name=$user->data->display_name;
				$user_data->id=$user->ID; //integer;
				$user_data->first_name = get_user_meta( $user->ID, 'first_name', true );
				$user_data->last_name = get_user_meta( $user->ID, 'last_name', true );					
				return rest_ensure_response( $user_data );
				
			} else {
				return rest_ensure_response( array() ); // User not found.
			}

		}

		public function ams_ls_get_profile_meta( WP_REST_Request $request ) {

			$validate = $this->ams_basic_validate( $request, array( 'id' ) );
			if ( $validate != true ) {
				return $validate;
			}
			$user_id = sanitize_text_field( $request['id'] );
			$user_meta_data          = get_user_meta( $user_id, 'wp_user_avatar', true );
			$profile_image_full_path = wp_get_attachment_image_src( $user_meta_data );
			return rest_ensure_response( array( 'wp_user_avatar' => $profile_image_full_path ) );
		}

			/******

			 * get check out payment url.
			 * Note: This will be removed in next vesrion.
			 ******/
		public function ams_ls_get_order_payment_url( WP_REST_Request $request ) {

			$req      = $request->get_json_params();
			$validate = $this->ams_basic_validate( $req, array( 'order_id' ) );
			if ( $validate != true ) {
				return $validate;
			}
			$order_id = sanitize_text_field( $req['order_id'] );
			$order    = wc_get_order( $order_id );  // Returns WC_Product|null|false
			if ( ! isset( $order ) || $order == false ) {
				$error = new WP_Error();
				$error->add( 'message', __( 'The order ID appears to be invalid. Please try again.' ) );
				return $error;
			}  // Verify Valid Order ID
			
			// Check order ownership - user must be admin or order owner
			$order_customer_id = $order->get_customer_id();
			$current_user_id = get_current_user_id();
			if (!current_user_can('manage_options') && $order_customer_id != $current_user_id) {
				return new WP_Error(
					'rest_forbidden',
					__('You do not have permission to access this order.'),
					array('status' => 403)
				);
			}
			
			$pay_now_url = esc_url( $order->get_checkout_payment_url() );
			return( rest_ensure_response( html_entity_decode( $pay_now_url ) ) );
		}

		public function ams_ls_wp_get_user_auth_cookies( WP_REST_Request $request ) {  

			$user_id = sanitize_text_field($request->get_param('user_id'));
			$user = get_user_by( 'ID', $user_id ); // | ID | slug | email | login.
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				$user->data->user_pass = '';
				$user->data->user_activation_key =  " ";
				$user->data->id = $user->ID; //integer
				$user->data->first_name = get_user_meta( $user->ID, 'first_name', true );
				$user->data->last_name = get_user_meta( $user->ID, 'last_name', true );			
				$user->data->roles = $user->roles;
				
				####get user wp_generate_auth_cookie####
				$expiration = time() + apply_filters('auth_cookie_expiration', 14 * DAY_IN_SECONDS , $user->ID, true);

				// FIXED COOKIE HASH LOGIC
				if ( defined('COOKIEHASH') && COOKIEHASH ) {
				    $cookie_hash = COOKIEHASH;
				} else {
				    $site_url = get_site_url();
				    $cookie_hash = md5( rtrim($site_url, '/') );
				}

				$user->data->expiration = $expiration;
				$user->data->cookie_hash = $cookie_hash;

				$user->data->wordpress_logged_in_ = wp_generate_auth_cookie($user->ID, $expiration, 'logged_in');
				$user->data->wordpress_ = wp_generate_auth_cookie($user->ID, $expiration, 'secure_auth');
					
				########################################
				return rest_ensure_response( $user->data );
			} else {
				return new WP_Error('ams_error', 'Something went wrong. Please contact support.', array('status' => 500));
			}
		}
		
		/******

		 * Sends rest password link on user's email.
		 ******/

		public function ams_ls_send_password_reset_link( WP_REST_Request $request ) {
				$req      = $request->get_json_params();
				$validate = $this->ams_basic_validate( $req, array( 'email' ) );
			if ( $validate != true ) {
				return $validate;
			}
				$email = sanitize_email( $req['email'] );
				$user  = get_user_by( 'email', $email );
			if ( ! $user ) {
				$error = new WP_Error();
				$error->add( 'message', __( 'The email address appears to be incorrect. Please try again.' ) );
				return $error;
			}
				$firstname  = $user->first_name;
				$email      = $user->user_email;
				$user_login = $user->user_login;
				$retrieve_password = retrieve_password($user_login);
				
				if ( isset( $retrieve_password->errors ) ) {
					$error_message = strip_tags( $this->ams_convert_error_to_string( $retrieve_password->errors ) );
					$error = new WP_Error();
					$error->add( 'message', __( $error_message . '' ) );
					return $error;
				}
				
				return( rest_ensure_response( array( 'message' => 'Reset Password link sent successfully!' ) ) );

		}


		/******

		 * Calculates shipping methods based on cart-items (line_items) , shipping address and coupon.
		 ******/
		 
		public function ams_ls_applicable_shipping_method( WP_REST_Request $request ) {
				
			$req = $request->get_json_params();
			$validate = $this->ams_basic_validate( $req, array( 'shipping', 'line_items' ) );
			if ( $validate != true ) {
				return $validate;
			}

			$shipping                = $req['shipping'];
			$line_items              = $req['line_items'];
			$customer_id = 0;
			if ( isset( $req['customer_id'] ) && $req['customer_id'] !== '' ) {
				$customer_id = absint( $req['customer_id'] );
				// When a specific customer_id is provided (non-guest), enforce ownership
				if ( $customer_id > 0 ) {
					$current_user_id = get_current_user_id();
					if ( $current_user_id !== $customer_id && ! current_user_can( 'manage_options' ) ) {
						return new WP_Error(
							'rest_forbidden',
							__( 'You do not have permission to access this user\'s data.' ),
							array( 'status' => 403 )
						);
					}
				}
			}
			
			$content = [];
			wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
			wc()->frontend_includes();
			WC()->session = new WC_Session_Handler();
			WC()->customer = new WC_Customer( $customer_id, true );
			WC()->initialize_cart();
			WC()->cart->empty_cart();
			foreach ( $line_items as $key => $value ) {  // prepare cart content which will be used for table rate plugin
				if ( array_key_exists( 'variation_id', $value ) ) {
					WC()->cart->add_to_cart($value['product_id'], $value['quantity'], $value['variation_id'], $value['variation'] );									
				}else{
					WC()->cart->add_to_cart($value['product_id'], $value['quantity'] );				
				}
			}

			WC()->customer->set_shipping_first_name(isset($shipping['first_name'])?$shipping['first_name']:'');
			WC()->customer->set_shipping_last_name(isset($shipping['last_name'])?$shipping['last_name']:'');
			WC()->customer->set_shipping_address_1(isset($shipping['address_1'])?$shipping['address_1']:'');
			WC()->customer->set_shipping_address_2(isset($shipping['address_2'])?$shipping['address_2']:'');
			WC()->customer->set_shipping_city(isset($shipping['city'])?$shipping['city']:'');
			WC()->customer->set_shipping_postcode(isset($shipping['postcode'])?$shipping['postcode']:'');
			WC()->customer->set_shipping_country(isset($shipping['country'])? $shipping['country'] :'');
			WC()->customer->set_shipping_state(isset($shipping['state'])? $shipping['state']: '');
			
			if(isset( $req[ 'coupon_lines' ] )){
				if(!empty( $req[ 'coupon_lines' ] )){
					$coupon_code = sanitize_text_field( $req[ 'coupon_lines' ][0]['code'] );
					WC()->cart->add_discount( $coupon_code );
				}				
			}
			
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();
			
			$packages = apply_filters('woocommerce_cart_shipping_packages', WC()->cart->get_shipping_packages());
			$shipping_packages = WC()->shipping->calculate_shipping($packages);
			
			$all_shipping_packages_rates = array();
			foreach($shipping_packages as $package){
				$pacakage_rates=array();
				foreach($package['rates'] as $rate){

					$pacakage_rate = array();
					$pacakage_rate['id']=(string)$rate->get_instance_id();
					$pacakage_rate['title']=$rate->get_label();
					$pacakage_rate['method_id']=$rate->get_method_id();
					$pacakage_rate['cost']=number_format((float)$rate->get_cost(), 2, '.', '');
					$pacakage_rate['tax']= number_format((float)$rate->get_shipping_tax(), 2, '.', '');
					array_push($pacakage_rates,$pacakage_rate);
				}
				if(empty($pacakage_rates)){
					return( rest_ensure_response( $pacakage_rates ) );
				}
				array_push($all_shipping_packages_rates,$pacakage_rates);
			}
			
			$result_methods=$all_shipping_packages_rates[0];
			for($i=1;$i<count($all_shipping_packages_rates);++$i){
				$result_methods = $this->merge_shipping_methods($result_methods,$all_shipping_packages_rates[$i]);				
			}	
			WC()->cart->empty_cart();
			WC()->session->destroy_session();
			return( rest_ensure_response( $result_methods ) );
		}


		public function ams_checkout_fields() {
			if ( in_array(
				'woocommerce-checkout-field-editor/woocommerce-checkout-field-editor.php',
				apply_filters( 'active_plugins', get_option( 'active_plugins' ) )
			)
			) {
					$result['checkout_shipping_fields']   = $this->ams_object_to_array( get_option( 'wc_fields_shipping', array() ) );
					$result['checkout_billing_fields']    = $this->ams_object_to_array( get_option( 'wc_fields_billing', array() ) );
					$result['checkout_additional_fields'] = $this->ams_object_to_array( get_option( 'wc_fields_additional', array() ) );
					return rest_ensure_response( $result );
			} else {
				return rest_ensure_response( array() );
			}
		}

		public function ams_wc_points_rewards_effective_discount( WP_REST_Request $request ) {
			if ( in_array(
				'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php',
				apply_filters( 'active_plugins', get_option( 'active_plugins' ) )
			)
			) {
				$params                            = $request->get_params();
				$line_items                        = $params['line_items'];
				$customer_id                       = $params['customer_id'];
				$wc_points_rewards_discount_amount = $params['wc_points_rewards_discount_amount'];
				$granted_user_discount             = 0;

				// Construct local cart
				if ( is_null( WC()->cart ) ) {
					wc_load_cart();
				}
				WC()->cart->empty_cart();
				foreach ( $line_items as $key => $value ) {
					if(!isset($value['variation'])){$value['variation']= [];}
					if ( array_key_exists( 'variation_id', $value ) ) {
						WC()->cart->add_to_cart( $value['product_id'], $value['quantity'], $value['variation_id'], $value['variation'] );
					} else {
						WC()->cart->add_to_cart( $value['product_id'], $value['quantity'] );
					}
				}

				// Verify Global settings of points and reward plugin
				$available_user_discount = WC_Points_Rewards_Manager::get_users_points_value( $customer_id );

				// no discount
				if ( $available_user_discount <= 0 ) {
					// return 0;
					$error = new WP_Error();
					$error->add( 'message', __( 'No reward point available.' ) );
					return $error;
				}

				if ( 'yes' === get_option( 'wc_points_rewards_partial_redemption_enabled' ) && $wc_points_rewards_discount_amount ) {
					$requested_user_discount = WC_Points_Rewards_Manager::calculate_points_value( $wc_points_rewards_discount_amount );
					if ( $requested_user_discount > 0 && $requested_user_discount < $available_user_discount ) {
						$available_user_discount = $requested_user_discount;
					}
				}

				// Limit the discount available by the global minimum discount if set.
				$minimum_discount = get_option( 'wc_points_rewards_cart_min_discount', '' );
				if ( $minimum_discount > $available_user_discount ) {
					// return 0;
					$error = new WP_Error();
					$error->add( 'message', __( 'Please enter atleast '.$minimum_discount.' points global minimum discount error.' ) );
					return $error;
				}

				// apply product level setting of point and reward plugin.
				$discount_applied = 0;

				if ( ! did_action( 'woocommerce_before_calculate_totals' ) ) {
					WC()->cart->calculate_totals();
				}
				
				$max_points_discount_of_all_products = 0;			
				foreach ( WC()->cart->get_cart() as $item_key => $item ) {

					$discount     = 0;
					$max_discount = WC_Points_Rewards_Product::get_maximum_points_discount_for_product( $item['data'] );

					if ( is_numeric( $max_discount ) ) {

						// adjust the max discount by the quantity being ordered
						$max_discount *= $item['quantity'];


						// if the discount available is greater than the max discount, apply the max discount
						$discount = ( $available_user_discount <= $max_discount ) ? $available_user_discount : $max_discount;

						// Max should be product price. As this will be applied before tax, it will respect other coupons.
					} else {
						/*
						 * Only exclude taxes when configured to in settings and when generating a discount amount for displaying in
						 * the checkout message. This makes the actual discount money amount always tax inclusive.
						 */
						if ( 'exclusive' === get_option( 'wc_points_rewards_points_tax_application', wc_prices_include_tax() ? 'inclusive' : 'exclusive' ) && $for_display ) {
							if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
								$max_discount = wc_get_price_excluding_tax( $item['data'], array( 'qty' => $item['quantity'] ) );
							} elseif ( method_exists( $item['data'], 'get_price_excluding_tax' ) ) {
								$max_discount = $item['data']->get_price_excluding_tax( $item['quantity'] );
							} else {
								$max_discount = $item['data']->get_price( 'edit' ) * $item['quantity'];
							}
						} else {
							if ( function_exists( 'wc_get_price_including_tax' ) ) {
								$max_discount = wc_get_price_including_tax( $item['data'], array( 'qty' => $item['quantity'] ) );
							} elseif ( method_exists( $item['data'], 'get_price_including_tax' ) ) {
								$max_discount = $item['data']->get_price_including_tax( $item['quantity'] );
							} else {
								$max_discount = $item['data']->get_price( 'edit' ) * $item['quantity'];
							}
						}

						// if the discount available is greater than the max discount, apply the max discount
						$discount = ( $available_user_discount <= $max_discount ) ? $available_user_discount : $max_discount;
					}
					
					$max_points_discount_of_all_products += $max_discount ; 
					
					// add the discount to the amount to be applied
					$discount_applied += $discount;

					// reduce the remaining discount available to be applied
					$available_user_discount -= $discount;
				}
				// limit customer if requested amount to avail discount exceeds the product's maximum discount. 
				/*
				if($wc_points_rewards_discount_amount > $max_points_discount_of_all_products){
							$error = new WP_Error();
							$error->add( 'message', __( 'You cannot enter more than '.$max_points_discount_of_all_products.' points on these products.' ) );
							return $error;	
				}
				*/
				
				$existing_discount_amounts = version_compare( WC_VERSION, '3.0.0', '<' )? WC()->cart->discount_total: WC()->cart->get_cart_discount_total();
					
				// if the available discount is greater than the order total, make the discount equal to the order total less any other discounts
				if ( version_compare( WC_VERSION, '3.0.0', '<' ) ) {
					if ( 'no' === get_option( 'woocommerce_prices_include_tax' ) ) {
						$discount_applied = max( 0, min( $discount_applied, WC()->cart->subtotal_ex_tax - $existing_discount_amounts ) );

					} else {
						$discount_applied = max( 0, min( $discount_applied, WC()->cart->subtotal - $existing_discount_amounts ) );

					}
				} else {
					if ( 'no' === get_option( 'woocommerce_prices_include_tax' ) ) {
						$discount_applied = max( 0, min( $discount_applied, WC()->cart->subtotal_ex_tax - $existing_discount_amounts ) );

					} else {
						$discount_applied = max( 0, min( $discount_applied, WC()->cart->subtotal - $existing_discount_amounts ) );
					}
				}
				
				// limit the discount available by the global maximum discount if set
				$max_discount = get_option( 'wc_points_rewards_cart_max_discount' );

				if ( false !== strpos( $max_discount, '%' ) ) {
					$max_discount = ams_ams_calculate_discount_modifier( $max_discount );
				}
				$max_discount = filter_var( $max_discount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );

				if ( $max_discount && $max_discount < $discount_applied ) {
					$error = new WP_Error();
					$error->add( 'message', __( 'You cannot enter more than '.$max_discount.' points.' ) );
					return $error;
					//$discount_applied = $max_discount;
				}
				$discount_applied = filter_var( $discount_applied, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
				
				// Calculate actual points being redeemed based on the effective discount
				$points_redeemed = WC_Points_Rewards_Manager::calculate_points_for_discount( $discount_applied );
				
				return rest_ensure_response( array( array( 
					'effective_discount_value' => (float) $discount_applied,
					'points_redeemed' => (int) $points_redeemed
				) ) );

			} else {
				return rest_ensure_response( array() );
			}
		}

		public function ams_wc_points_rewards_settings() {
			if ( in_array(
				'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php',
				apply_filters( 'active_plugins', get_option( 'active_plugins' ) )
			)
			) {

				$result['wc_points_rewards_cart_max_discount']          = get_option( 'wc_points_rewards_cart_max_discount' );
				$result['wc_points_rewards_write_review_points']        = get_option( 'wc_points_rewards_write_review_points' );
				$result['wc_points_rewards_account_signup_points']      = get_option( 'wc_points_rewards_account_signup_points' );
				$result['wc_points_rewards_points_expiry']              = get_option( 'wc_points_rewards_points_expiry' );
				$result['wc_points_rewards_points_expire_points_since'] = get_option( 'wc_points_rewards_points_expire_points_since' );
				$result['wc_points_rewards_version']                    = get_option( 'wc_points_rewards_version' );
				$result['wc_points_rewards_earn_points_ratio']          = get_option( 'wc_points_rewards_earn_points_ratio', '' );
				$result['wc_points_rewards_earn_points_rounding']       = get_option( 'wc_points_rewards_earn_points_rounding' );
				$result['wc_points_rewards_redeem_points_ratio']        = get_option( 'wc_points_rewards_redeem_points_ratio', '' );
				$result['wc_points_rewards_partial_redemption_enabled'] = get_option( 'wc_points_rewards_partial_redemption_enabled' );
				$result['wc_points_rewards_cart_min_discount']          = get_option( 'wc_points_rewards_cart_min_discount' );
				$result['wc_points_rewards_max_discount']               = get_option( 'wc_points_rewards_max_discount' );
				$result['wc_points_rewards_points_tax_application']     = get_option( 'wc_points_rewards_points_tax_application' );
				$result['wc_points_rewards_points_expiry_number']       = get_option( 'wc_points_rewards_points_expiry_number' );
				$result['wc_points_rewards_points_expiry_period']       = get_option( 'wc_points_rewards_points_expiry_period' );
				$result['wc_points_rewards_single_product_message']     = get_option( 'wc_points_rewards_single_product_message' );
				$result['wc_points_rewards_variable_product_message']   = get_option( 'wc_points_rewards_variable_product_message' );
				$result['wc_points_rewards_earn_points_message']        = get_option( 'wc_points_rewards_earn_points_message' );
				$result['wc_points_rewards_redeem_points_message']      = get_option( 'wc_points_rewards_redeem_points_message' );
				$result['wc_points_rewards_thank_you_message']          = get_option( 'wc_points_rewards_thank_you_message' );

				return rest_ensure_response( $result );
			} else {
				return rest_ensure_response( array() );
			}
		}

		public function ams_change_password(WP_REST_Request $request){

			$req = $request->get_json_params();

			$user       = wp_authenticate( sanitize_email( $req['username'] ), sanitize_text_field( $req['password'] ) );  // htmlspecialchars
			$customer_id = sanitize_text_field( $req['customer_id'] );
			$old_password = sanitize_text_field( $req['old_password'] );
			$new_password = sanitize_text_field( $req['new_password'] );
			$confirm_password = sanitize_text_field( $req['confirm_password'] );
			
			$user = get_user_by( 'id', $customer_id );
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			}
			
			$x = wp_check_password( $old_password, $user->data->user_pass, $user->data->ID );
			
			if ( isset( $x->errors ) ) {
				$error_message = strip_tags( ams_convert_error_to_string( $x->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			}		
			if($x)
			{	 		
				if($new_password == $old_password)
				{
					return new WP_Error( 'ams_error', 'Sorry, your new password and old password can not be the same.', array( 'status' => 422 ) );
				}
				if($new_password == $confirm_password)
				{
					$user_data['ID'] = $user->data->ID;
					$user_data['user_pass'] = $new_password;
					$uid = wp_update_user( $user_data );
					//wp_set_password($new_password , $user_id);
					if($uid) 
					{
						unset($passdata);
						$user->data->user_pass = '';
						$user->data->user_activation_key = '';
						$user->data->id = $user->ID; //integer
						$user->data->first_name = get_user_meta( $user->ID, 'first_name', true );
						$user->data->last_name = get_user_meta( $user->ID, 'last_name', true );														
						$user->data->roles = $user->roles;				
						return rest_ensure_response( $user->data );
					} else {						
						return new WP_Error( 'ams_error', 'Sorry, your account was not updated. Please try again later.', array( 'status' => 422 ) );
					}
				}
				else
				{					
					return new WP_Error( 'ams_error', 'Sorry, both your passwords do not match. Please try again. ', array( 'status' => 422 ) );				
				}			
			} 
			else 
			{			
				return new WP_Error( 'ams_error', 'Sorry, your old password is not correct. Please try again.', array( 'status' => 422 ) );
			}		
				
		}

		public function ams_disable_plugins($plugins){
			// Returning an empty array will instruct the loader not to load any plugins at all.
			
			// HOWEVER, if you need to keep certain plugins activated, you can do this very easily.
			// Just modify the array below to contain the file names of the plugins you need to stay active.
			// Example: plugin file is located in "some-plugin/some-plugin.php"
			// return array('some-plugin/some-plugin.php');
			$array = ['appmysite/appmysite.php'];
			if(class_exists( 'WooCommerce' )){
				array_push($array,'woocommerce/woocommerce.php');
			}
			
			if (class_exists( 'WC_Points_Rewards' ))
			{
				array_push($array,'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php');
			}

			if (class_exists( 'WC_Checkout_Field_Editor' ))
			{
				array_push($array,'woocommerce-checkout-field-editor/woocommerce-checkout-field-editor.php');
			}
			
			return $array;
		}

		public function ams_disable_theme($theme)
		{
			
				// Check if twenty * is installed, and if so, activate it.
				$themes = wp_get_themes();
				
				if(array_key_exists('twentytwentyfour', $themes))
					return 'twentytwentyfour';
				else if(array_key_exists('twentytwentythree', $themes))
					return 'twentytwentythree';
				else if(array_key_exists('twentytwentytwo', $themes))
					return 'twentytwentytwo';
				else if(array_key_exists('twentytwentyone', $themes))
					return 'twentytwentyone';
				else if(array_key_exists('twentytwenty', $themes))
					return 'twentytwenty';
				else if(array_key_exists('twentynineteen', $themes))
					return 'twentynineteen';
				else if(array_key_exists('twentyeighteen', $themes))
					return 'twentyeighteen';
				else if(array_key_exists('twentyseventeen', $themes))
					return 'twentyseventeen';
				else if(array_key_exists('twentysixteen', $themes))
					return 'twentysixteen';
				else if(array_key_exists('twentyfifteen', $themes))
					return 'twentyfifteen';
				else if(array_key_exists('twentyfourteen', $themes))
					return 'twentyfourteen';
				else if(array_key_exists('twentythirteen', $themes))
					return 'twentythirteen';
				else if(array_key_exists('twentytwelve', $themes))
					return 'twentytwelve';
				else if(array_key_exists('twentyeleven', $themes))
					return 'twentyeleven';
				else if(array_key_exists('twentyten', $themes))
					return 'twentyten';
			
			// No default themes are installed, so we'll stick with the current active theme.
			return $theme;
		}
		
		public function ams_get_active_plugins( WP_REST_Request $request ){
			
			
			$apl=get_option('active_plugins');
			$plugins=get_plugins();
			$activated_plugins=array();
			foreach ($apl as $p){           
				if(isset($plugins[$p])){
					 array_push($activated_plugins, $plugins[$p]);
				}           
			}

			return (rest_ensure_response($activated_plugins));
			
		}
		
		public function ams_ls_user_wishlist( WP_REST_Request $request ) {  
			$param = $request->get_params();					
			
			$per_page = !empty($param['per_page']) ? intval($param['per_page']) : 10;
			$page = !empty($param['page']) ? intval($param['page']) : 1;
			
			$user_id = $request->get_param('user_id');
			
			$user = get_user_by('ID', $user_id);
			if (!$user) {
				return new WP_Error('ams_error', 'User not found.', ['status' => 500]);
			}
			
			$ams_wishlist = get_user_meta($user_id, 'ams_wishlist', true);
		
			if (!is_array($ams_wishlist) || empty($ams_wishlist)) {
				return rest_ensure_response([]);
			}
		
			$total_records = count($ams_wishlist);
			$total_pages = ceil($total_records / $per_page);
			
			if ($page > $total_pages || $page < 1) {
				return rest_ensure_response([]);
			}
		
			// Paginate
			$offset = ($page - 1) * $per_page;
			$ams_wishlist = array_slice($ams_wishlist, $offset, $per_page);
		
			// Ensure all values are integers
			$ams_wishlist_int = array_map('intval', $ams_wishlist);
		
			if (empty($ams_wishlist_int)) {
				return rest_ensure_response([]);
			}
		
			// Fetch WooCommerce Products
			$request    = new WP_REST_Request( 'GET', '/wc/v3/products' );
			$parameters = array( 'include' => $ams_wishlist_int );							
			if ( ! empty( $per_page ) ) {
				$parameters += array( 'per_page' => 99 );
			}
			$request->set_query_params( $parameters );
			$response = rest_do_request( $request );
			$server   = rest_get_server();
			$data     = $server->response_to_data( $response, false );

			// Manually reorder the products based on the original wishlist order
			$ordered_data = [];
			foreach ($ams_wishlist_int as $product_id) {
				foreach ($data as $product) {
					if ($product['id'] === $product_id) {
						$ordered_data[] = $product;
						break;
					}
				}
			}
			return rest_ensure_response($ordered_data);
		}
		
		public function ams_ls_user_wishlist_add( WP_REST_Request $request ) {
			$user_id = $request->get_param('user_id');
			$product_ids = $request->get_param('product_ids');  // This will be an array of product IDs
			
			$user = get_user_by('ID', $user_id);
			
			if (isset($user->errors)) {
				$error_message = strip_tags($this->ams_convert_error_to_string($user->errors));
				$error = new WP_Error();
				$error->add('message', __($error_message . ''));
				return $error;
			} elseif (isset($user->data)) {
				// implement wishlist
				$user_wishlist = get_user_meta($user_id, 'ams_wishlist', true);
				
				if (metadata_exists('user', $user_id, 'ams_wishlist')) {
					
					// Ensure user_wishlist is an array (if it's not, initialize it as an empty array)
					if (!is_array($user_wishlist)) {
						$user_wishlist = []; // Reset to empty array if the wishlist is not an array
					}
		
					// Ensure user_wishlist is a flat array of integers before merging
					$user_wishlist = array_map('intval', $user_wishlist);
		
					// Merge the incoming product IDs into the existing wishlist and remove duplicates
					$merged_wishlist = array_unique(array_merge($product_ids, $user_wishlist));
		
					// Update the user meta with the new wishlist
					update_user_meta($user_id, 'ams_wishlist', $merged_wishlist);
		
					// Return the full wishlist (updated)
					$wishlist = array_values($merged_wishlist); // Reindex the array
				} else {
					// If no wishlist exists, create a new array with the product IDs
					$user_wishlist = $product_ids;
					update_user_meta($user_id, 'ams_wishlist', $user_wishlist);
					$wishlist = $product_ids;  // Return the product IDs array directly
				}
				
				return rest_ensure_response($wishlist);
			} else {
				return new WP_Error('ams_error', 'User not found.', ['status' => 500]);
			}
		}
		
		public function ams_ls_user_wishlist_remove( WP_REST_Request $request ) {
			$user_id = $request->get_param('user_id');
			$product_ids_to_remove = $request->get_param('product_ids'); // Array of product IDs to remove
		
			// Get user object
			$user = get_user_by('ID', $user_id);
			if (isset($user->errors)) {
				$error_message = strip_tags($this->ams_convert_error_to_string($user->errors));
				$error = new WP_Error();
				$error->add('message', __($error_message . ''));
				return $error;
			} elseif (isset($user->data)) {
				// Retrieve current wishlist from user meta
				$user_wishlist = get_user_meta($user_id, 'ams_wishlist', true);
		
				// If no wishlist exists, return an empty array
				if (empty($user_wishlist)) {
					return rest_ensure_response([]);
				}
		
				// Step 1: Flatten the wishlist if it's a nested array
				if (is_array($user_wishlist) && isset($user_wishlist[0]) && is_array($user_wishlist[0])) {
					$flattened_wishlist = array_merge(...$user_wishlist); // Flatten the nested array into a flat one
				} else {
					$flattened_wishlist = $user_wishlist; // Already a flat array
				}
		
				// Step 2: Remove the product IDs from the wishlist
				$updated_wishlist = array_diff($flattened_wishlist, $product_ids_to_remove);
		
				// Step 3: If wishlist is empty after removal, clear it
				if (empty($updated_wishlist)) {
					update_user_meta($user_id, 'ams_wishlist', []);
					return rest_ensure_response([]);
				}
		
				// Step 4: Reindex the array to return a flat, indexed array
				$updated_wishlist = array_values($updated_wishlist);
		
				// Step 5: Update the user meta with the new wishlist (no nesting)
				update_user_meta($user_id, 'ams_wishlist', $updated_wishlist);
		
				// Step 6: Return the updated wishlist as a flat array
				return rest_ensure_response($updated_wishlist);
			} else {
				return new WP_Error('ams_error', 'User not found.', ['status' => 500]);
			}
		}
		
		public function ams_ls_user_wishlist_clear( WP_REST_Request $request ) {  

			$user_id = sanitize_text_field($request->get_param('user_id'));
			
			$user = get_user_by( 'ID', $user_id ); // | ID | slug | email | login.
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				//implement wishlist 
				
				update_user_meta($user_id, 'ams_wishlist', []);
				
				return rest_ensure_response( get_user_meta($user_id, 'ams_wishlist', true));
			} else {
				return new WP_Error('ams_error', 'User not found.', array('status' => 500));
			}
		}


		public function ams_ls_user_bookmarks( WP_REST_Request $request ) {  
			
			$param        = $request->get_params();					
			// The number of records to display per page
			if ( ! empty( $param['per_page'] ) ) {
				$per_page = $param['per_page'];
			}else{$per_page = 10;}
			// Pagination, starts from 1.
			if ( ! empty( $param['page'] ) ) {
				$page = $param['page'];
			}
			
			$user_id = $request->get_param('user_id');
			
			$user = get_user_by( 'ID', $user_id ); // | ID | slug | email | login.
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				//implement bookmark 				
				$ams_bookmarks = get_user_meta($user_id, 'ams_bookmarks', true);
				if(gettype($ams_bookmarks)!="array" || empty($ams_bookmarks)){ 
					return rest_ensure_response( [] );
				}

				###########Pagination Logic####################################		
				$total_records = count($ams_bookmarks);
				$total_pages   = ceil($total_records / $per_page);
				if ($page > $total_pages) {
					//$page = $total_pages; //commented to return function if bookmark is empty before going to list posts
					return rest_ensure_response( [] );
				}
				if ($page < 1) {
					$page = 1;
				}
				$offset = ($page - 1) * $per_page;
				$ams_bookmarks = array_slice($ams_bookmarks, $offset, $per_page);
				###########End Pagination######################################	

				// Ensure that all items in $ams_bookmarks are arrays, and filter out any non-arrays
				$ams_bookmarks = array_filter($ams_bookmarks, 'is_array');
				
				if (empty($ams_bookmarks)) {
					return rest_ensure_response([]);
				}

				// Merge arrays
				$ams_bookmark_int = array_map('intval', array_merge(...$ams_bookmarks));
				
				###########Retrieve The Products###############################
				$request    = new WP_REST_Request( 'GET', '/wp/v2/posts' );
				$parameters = array( 'include' => $ams_bookmark_int);
				if ( ! empty( $per_page ) ) {
					$parameters += array( 'per_page' => 99 );
				}
				$request->set_query_params( $parameters );
				$response = rest_do_request( $request );
				$server   = rest_get_server();
				$data     = $server->response_to_data( $response, false );

				// Manually reorder the products based on the original wishlist order
				$ordered_data = [];
				foreach ($ams_bookmark_int as $post_id) {
					foreach ($data as $post) {
						if ($post['id'] === $post_id) {
							$ordered_data[] = $post;
							break;
						}
					}
				}
				return rest_ensure_response($ordered_data);

				###########End Retrieve The Products###############################
				
			} else {
				return new WP_Error('ams_error', 'User not found.', array('status' => 500));
			}
		}
		
		public function ams_ls_user_bookmarks_add( WP_REST_Request $request ) {  

			$user_id = $request->get_param('user_id');
			$post_ids = $request->get_param('post_ids');
			
			$user = get_user_by( 'ID', $user_id ); // | ID | slug | email | login.
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				//implement bookmark 
				
				$ams_bookmarks = get_user_meta($user_id, 'ams_bookmarks', true);		//this will return a array.
									
				if (!empty($ams_bookmarks)) {  
					// Case 1: If the 'ams_bookmarks' metadata exists.			
					if(is_array($ams_bookmarks)){
						$ams_bookmark_formatted = array_map('intval', array_merge(...$ams_bookmarks));
						
						if(!in_array($post_ids,$ams_bookmark_formatted)){
							$merged_bookmarks = array_unique(array_merge($post_ids, $ams_bookmark_formatted));
							$ams_bookmarks = [$merged_bookmarks];
						}else{
							//do nothing ad product already exixts
							return new WP_Error( 'ams_error', 'Resource already exixts.', array( 'status' => 409 ) );
						}
						
					}else{
						$ams_bookmarks=[$post_ids]; //create a new array with given post
					}
					update_user_meta($user_id, 'ams_bookmarks', $ams_bookmarks);
					
				}else{  
					// Case 2: If 'ams_bookmarks' metadata doesn't exist for the user.
					$ams_bookmarks = [$post_ids]; //create a new array with given post
					update_user_meta($user_id, 'ams_bookmarks', $ams_bookmarks);
					$bookmarks = get_user_meta($user_id, 'ams_bookmarks', true); 						
				}

				// Fetch the updated bookmarks
				$bookmarks = get_user_meta($user_id, 'ams_bookmarks', true); 

				// Format bookmarks if necessary
				if (is_array($bookmarks) && !empty($bookmarks)) {
					$formatted_bookmarks = [];
					foreach ($bookmarks[0] as $keys => $values) {
						$formatted_bookmarks[] = $values;
					}
					$bookmarks = array_values($formatted_bookmarks);
				}

				return rest_ensure_response($bookmarks);
			} else {
				return new WP_Error('ams_error', 'User not found.', array('status' => 500));
			}
		}
		
		public function ams_ls_user_bookmarks_remove( WP_REST_Request $request ) {  

			$user_id = $request->get_param('user_id');
			$post_ids = $request->get_param('post_ids');
			
			$user = get_user_by( 'ID', $user_id ); // | ID | slug | email | login.
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				//implement bookmarks
				$ams_bookmarks = get_user_meta($user_id, 'ams_bookmarks', true);		//this will return a array.
				
				if (empty($ams_bookmarks)) {
					return rest_ensure_response([]);  // Return empty if no bookmarks exist
				}

				$ams_bookmark_formatted = array_map('intval', array_merge(...$ams_bookmarks)); //this conversion is needed to check the post ids in ams_bookmark nested array
				$found_ids = array_intersect($post_ids, $ams_bookmark_formatted);

				if(!empty($found_ids)){
					$updated_bookmarks = array_diff($ams_bookmark_formatted, $found_ids);
					if(empty($updated_bookmarks)){
						update_user_meta($user_id, 'ams_bookmarks', []);  
                		return rest_ensure_response([]);
					}else{
						$nested_bookmarks = [array_values($updated_bookmarks)];  //again converting to previous format to update in metadata to give list properly in list api
						update_user_meta($user_id, 'ams_bookmarks', $nested_bookmarks);
					}
				}else{
					return rest_ensure_response($ams_bookmark_formatted);
				}
				
				/*******************************************************************/
				$bookmarksArray = get_user_meta($user_id, 'ams_bookmarks', true); 
				if (is_array($bookmarksArray) && !empty($bookmarksArray)) {
					$updated_bookmarks = [];
					foreach($bookmarksArray[0] as $keys => $values){
						$innerArray[] = $values;
					}
					$bookmarks = array_values($innerArray);
				}else {
					$bookmarks = [];
				}
				/*******************************************************************/
				
				return rest_ensure_response($bookmarks);
			} else {
				return new WP_Error('ams_error', 'User not found.', array('status' => 500));
			}
		}
		
		public function ams_ls_user_bookmarks_clear( WP_REST_Request $request ) {  

			$user_id = $request->get_param('user_id');
			
			$user = get_user_by( 'ID', $user_id ); // | ID | slug | email | login.
			if ( isset( $user->errors ) ) {
				$error_message = strip_tags( $this->ams_convert_error_to_string( $user->errors ) );
				$error         = new WP_Error();
				$error->add( 'message', __( $error_message . '' ) );
				return $error;
			} elseif ( isset( $user->data ) ) {
				//implement wishlist 
				
				update_user_meta($user_id, 'ams_bookmarks', []);
				
				return rest_ensure_response( get_user_meta($user_id, 'ams_bookmarks', true));
			} else {
				return new WP_Error('ams_error', 'User not found.', array('status' => 500));
			}
		}

		private function ams_object_to_array( $data ) {
			if ( is_array( $data ) || is_object( $data ) ) {
				$result = array();
				foreach ( $data as $key => $value ) {
					$value['field_name'] = $key;
					$value['options']    = array_values( $value['options'] );
					$result[]            = $value;

				}
				return $result;
			}
			return $data;
		}
		
		private function ams_build_category_tree( $flat, $pidKey, $idKey = null ) {
			$grouped = array();
			foreach ( $flat as $sub ) {
				$grouped[ $sub[ $pidKey ] ][] = $sub;
			}
			$level     = 0;
			$fnBuilder = function( $siblings, $level ) use ( &$fnBuilder, $grouped, $idKey ) {
				foreach ( $siblings as $k => $sibling ) {
					if ( $sibling['slug'] == 'uncategorized' ) {
						unset( $siblings[ $k ] );
						continue;
					}
					$id                     = $sibling[ $idKey ];
					$sibling['description'] = '';
					$level++;
					if ( isset( $grouped[ $id ] ) ) {
						$sibling['depth']    = $level;
						$sibling['children'] = array_values( $fnBuilder( $grouped[ $id ], $level ) );
					} else {
						$sibling['depth']    = $level;
						$sibling['children'] = array();
					}
					$siblings[ $k ] = $sibling;
					$level--;
				}
				return $siblings;
			};

			if ( isset( $grouped[0] ) ) {
				$tree = $fnBuilder( $grouped[0], $level );
			}
			if ( ! empty( $tree ) ) {
				return array_values( $tree );
			} else {
				foreach ( $flat as $key => $value ) {
					if ( $value['slug'] == 'uncategorized' ) {
						unset( $flat[ $key ] );
						continue;
					}
					$flat[ $key ]['children'] = array();
				}
				return array_values( $flat );
			}
		}

		private function ams_convert_error_to_string( $er ) {
			 $string = ' ';
			foreach ( $er as $key => $value ) {

				$string = $string . '' . $key . ':';
				foreach ( $value as $newkey => $newvalue ) {

					$string = $string . '' . $newvalue . ' ';
				}
			}
			 $string = str_replace( 'Lost your password?', '', $string );
			 $string = str_replace( 'Error:', '', $string );
			 $string = str_replace( '[message]', '', $string );
			 return( $string );
		}

	/**
	 * Permission callback for admin-only endpoints
	 * Requires user to have 'manage_options' capability (administrator).
	 * Works with both WordPress session and WooCommerce REST API (consumer key/secret) auth.
	 *
	 * @param WP_REST_Request $request The REST request object
	 * @return bool True if authorized, false otherwise
	 */
	public function ams_authorize_admin_only( $request ) {
		$user_id = get_current_user_id();
		return $user_id > 0 && current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for endpoints that require any authenticated user.
	 * Works with both WordPress session and WooCommerce REST API (consumer key/secret) auth.
	 *
	 * @param WP_REST_Request $request The REST request object
	 * @return bool True if authorized, false otherwise
	 */
	public function ams_authorize_authenticated( $request ) {
		return get_current_user_id() > 0;
	}

	/**
	 * Permission callback for user-specific data endpoints.
	 * Requires user_id parameter and validates ownership or admin access.
	 * Works with both WordPress session and WooCommerce REST API auth.
	 *
	 * @param WP_REST_Request $request The REST request object
	 * @return bool|WP_Error True if authorized, WP_Error otherwise
	 */
	public function ams_authorize_user_data_access( $request ) {
		$current_user_id = get_current_user_id();
		if ( $current_user_id === 0 ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access this endpoint.' ),
				array( 'status' => 401 )
			);
		}

		// Get user_id from request - check 'user_id', 'customer_id', and 'id' parameters
		$user_id = $request->get_param( 'user_id' );
		if ( empty( $user_id ) ) {
			$user_id = $request->get_param( 'customer_id' );
		}
		if ( empty( $user_id ) ) {
			$user_id = $request->get_param( 'id' );
		}

		// user_id is required for user-specific endpoints
		if ( empty( $user_id ) ) {
			return new WP_Error(
				'rest_missing_user_id',
				__( 'user_id parameter is required for this endpoint.' ),
				array( 'status' => 400 )
			);
		}

		// Admins can access any user's data
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Regular users can only access their own data
		if ( $current_user_id === intval( $user_id ) ) {
			return true;
		}
			
			return new WP_Error(
				'rest_forbidden',
				__('You do not have permission to access this user\'s data.'),
				array('status' => 403)
			);
		}

		private function ams_basic_validate( $request, $keys ) {
			foreach ( $keys as $key => $value ) {

				if ( ! isset( $request[ $value ] ) ) {
					status_header( 400 );
					echo ( json_encode(
						array(
							'message' => 'There is a problem with your input!',
							'error'   => $value . ': Field is required!',
						),
						JSON_UNESCAPED_UNICODE
					) );
					die();
				}
				if ( empty( $request[ $value ] ) ) {
					status_header( 400 );
					echo ( json_encode(
						array(
							'message' => 'There is a problem with your input!',
							'error'   => $value . ': Can not be empty!',
						),
						JSON_UNESCAPED_UNICODE
					) );
					die();
				}
			}
			 return true;
		}

		private function merge_shipping_methods($methods1,$methods2){
			$result=array();
			foreach($methods1 as $method1){
				foreach($methods2 as $method2){
					$current=$method2;
					if(($method1["method_id"]=="local_pickup"||$method1["method_id"]=="free_shipping")&&(!in_array($methods2["method_id"],
					array("flat_rate","free_shipping","local_pickup")))){
						$current["method_id"]="flat_rate";
					}
					if($method1['method_id']=="local_pickup"&&$methods2["method_id"]=="free_shipping"){
					$current["method_id"]=$method1['method_id'];
					$current["id"]=$method1['id'];
					
					}
					if($method1['method_id']=="flat_rate"){
					$current["method_id"]=$method1['method_id'];
					$current["id"]=$method1['id'];
					
					}
					$current["title"].=" + ".$method1['title'];
					$current["cost"]+=$method1['cost'];				
					array_push($result,$current);
				}
				
			}		
			return $result;		
		}
		
			
			
	}

}