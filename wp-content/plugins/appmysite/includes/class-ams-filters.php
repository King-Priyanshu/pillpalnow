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
 
if ( !class_exists( 'AMS_Filters' ) ) {
		
	final class AMS_Filters{
		
		/**
		 * AMS_Filters Constructor.
		 **/
		
		function __construct() {
			
			$this->ams_filters();
			
		}
		
		private function ams_filters(){
			
			add_filter( 'user_has_cap', array($this,'ams_ls_allow_payment_without_login'), 10, 3 );
			
			add_filter( 'woocommerce_get_settings_products', array($this,'ams_ls_add_subtab_settings'), 10, 2 );
			
			add_filter( 'woocommerce_get_settings_products', array($this,'ams_ls_wc_get_cart_url'), 10, 2 );
			
			add_filter( 'woocommerce_rest_prepare_product_object', array($this,'ams_ls_prepare_product_images'), 10, 3 );
			
			add_filter( 'woocommerce_rest_prepare_product_object', array($this,'ams_ls_prepare_product_attributes'), 10, 3 );
			
			add_filter( 'woocommerce_rest_prepare_shop_order_object', array($this,'ams_ls_rest_apply_coupon'), 10, 3 );
			
			add_filter( 'woocommerce_get_shop_coupon_data', array($this,'ams_ls_create_virtual_coupon'), 10, 2 );
			
			add_action( 'woocommerce_order_status_processing', array($this,'ams_ls_order_processing') );
			
			add_filter( 'rest_prepare_user', array($this,'ams_ls_rest_prepare_user'), 10, 3 );
			
			add_action( 'rest_api_init', function(){
				add_action( 'pre_get_posts', array($this,'ams_ls_catalog_hidden_products_search_query_fix') );
            });
			
			
		}
		
		public function ams_ls_rest_prepare_user( $response, $post, $request ) {
			
			if ( empty( $response->data ) ) {
				return $response;
			}
			if(isset($response->data['id'])){
				$response->data['first_name'] = get_user_meta( $response->data['id'], 'first_name', true );
				$response->data['last_name'] = get_user_meta( $response->data['id'], 'last_name', true );
			}
			return $response;
		}
		
		public function ams_ls_allow_payment_without_login( $allcaps, $caps, $args ) {

			if ( ! isset( $caps[0] ) || $caps[0] != 'pay_for_order' ) {
				return $allcaps;
			}
			if ( ! isset( $_GET['key'] ) ) {
				return $allcaps;
			}
			$order = wc_get_order( $args[2] );
			if ( ! $order ) {
				return $allcaps;
			}
			$order_key                = $order->get_order_key();
			$order_key_check          = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			$allcaps['pay_for_order'] = ( $order_key == $order_key_check );
			return $allcaps;
		}
			
		public function ams_ls_add_subtab_settings( $settings ) {
			$current_section = get_option( 'woocommerce_default_catalog_orderby' );

			if ( isset( $current_section ) ) {
				$settings[] = array(
					'name'     => __( 'AMS WC Default Catalog Orderby Settings', 'woocommerce' ),
					'id'       => 'woocommerce_default_catalog_orderby',
					'label'    => 'Woocommerce Default Catalog Orderby',
					'type'     => 'select',
					'desc'     => __( 'This setting determines the sorting order of products in the catalog.', 'woocommerce' ),
					'desc_tip' => true,
					'options'  => array(
						'price'      => __( 'Sort by price (asc)', 'woocommerce' ),
						'date'       => __( 'Sort by most recent', 'woocommerce' ),
						'rating'     => __( 'Average rating', 'woocommerce' ),
						'popularity' => __( 'Popularity (sales)', 'woocommerce' ),
						'menu_order' => __( 'Default sorting (custom ordering + name)', 'woocommerce' ),
						'price-desc' => __( 'Sort by price (desc)', 'woocommerce' ),
					),
					'default'  => '',
					'value'    => get_option( 'woocommerce_default_catalog_orderby' ),

				);
				return $settings;
			} else {
				return $settings; // If not, return the standard settings
			}
			}
		
		public function ams_ls_wc_get_cart_url( $settings ) {
				
			$settings[] = array(
				'name'     => __( 'AMS WC Cart Page URL', 'woocommerce' ),
				'id'       => 'ams_wc_cart_url',
				'label'    => 'Woocommerce cart page URL.',
				'type'     => 'select',
				'desc'     => __( 'This setting determines the cart page url of store.', 'woocommerce' ),				
				'default'  => wc_get_cart_url()
			);
			return $settings;
		}

		public function ams_ls_prepare_product_images( $response, $post, $request ) {
			global $_wp_additional_image_sizes;

			if ( empty( $response->data ) ) {
				return $response;
			}
			if (is_array($response->data['images']) || is_object($response->data['images'])) {
				foreach ( $response->data['images'] as $key => $image ) {
						$image_info                                    = wp_get_attachment_image_src( $image['id'], 'thumbnail' );
						$response->data['images'][ $key ]['thumbnail'] = $image_info[0]??"";
						$image_info                                    = wp_get_attachment_image_src( $image['id'], 'medium' );
						$response->data['images'][ $key ]['medium']    = $image_info[0]??"";
				}
			}		
			return $response;
		}

		public function ams_ls_prepare_product_attributes( $response, $post, $request ) {
								
			if (is_array($response->data['attributes']) || is_object($response->data['attributes'])) {
				
				if ( empty( $response->data['attributes'] ) ) {
					return $response;
				}
				if ( $post->is_type( 'variable' ) ) { 
					$product_attributes = $post->get_attributes();
					$ams_wc_product_attributes=[];
					
					foreach( $post->get_attributes() as $attr_name => $attr ){				
						$attr->id = $attr->get_id();
						$attr->slug = $attr_name;
						$attr->terms = $attr->get_terms();
						$attr->options_slugs = $attr->get_slugs();
						$ams_wc_product_attributes["attribute_id_".$attr->get_id()] = $attr;	
					}
					
					foreach ( $response->data['attributes'] as $key => $attributes ) {
						if(isset($ams_wc_product_attributes['attribute_id_'.$attributes['id']])){
							$slug = $ams_wc_product_attributes['attribute_id_'.$attributes['id']]->slug;
							$terms = $ams_wc_product_attributes['attribute_id_'.$attributes['id']]->terms;
							$options_slugs = $ams_wc_product_attributes['attribute_id_'.$attributes['id']]->options_slugs;
						}else{
							$slug = '';
							$terms = [];
							$options_slugs = [];
						}

						$response->data['attributes'][ $key ]['slug'] = $slug;
						$response->data['attributes'][ $key ]['options_slugs'] = $options_slugs;
						$response->data['attributes'][ $key ]['terms'] = $terms;
					}
				}			
			}
			return $response;
		}
	
		public function ams_ls_rest_apply_coupon( $response, $object, $request ) {
						// this section is to apply coupon
				// If Reward point plugin is enabled #################
				if ( in_array(
					'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php',
					apply_filters( 'active_plugins', get_option( 'active_plugins' ) )
				)
				) {
					$already_redeemed                  = get_post_meta( $response->data['id'], '_wc_points_redeemed', true );
					$order                             = wc_get_order( $response->data['id'] );
					$wc_points_rewards_discount_amount = $order->get_meta( '_ams_wc_points_redeemed' );
					if ( !empty( $wc_points_rewards_discount_amount )&& (int)$wc_points_rewards_discount_amount>0){
						$customer_id                       = $request->get_param( 'customer_id' );
						$line_items                        = $request->get_param( 'line_items' );
						$reward_amount                     = $this->ams_get_points_rewards_discount_amount( $customer_id, $wc_points_rewards_discount_amount, $line_items ); // to be calculated
						$reward_coupon_code                = sprintf( 'wc_points_redemption_%s_%s_@%f', $customer_id, date( 'Y_m_d_h_i', current_time( 'timestamp' ) ), $reward_amount );
						// get actual point to be logged on the basis of allowed reward amount.
						$actual_points_to_redeemed = WC_Points_Rewards_Manager::calculate_points_for_discount( $reward_amount );

						// update_post_meta for reference
						if ( empty( $already_redeemed ) && $reward_amount > 0 ) {
							$results = $order->apply_coupon( $reward_coupon_code );
							update_post_meta( $response->data['id'], '_ams_wc_points_redeemed', $actual_points_to_redeemed );
							update_post_meta( $response->data['id'], '_ams_wc_points_rewards_discount_code', $reward_coupon_code );
							update_post_meta( $response->data['id'], '_ams_wc_points_rewards_discount_amount', $reward_amount );
							
							//HPOS Changes
							$order->update_meta_data( '_ams_wc_points_redeemed', $actual_points_to_redeemed );
							$order->update_meta_data( '_ams_wc_points_rewards_discount_code', $reward_coupon_code );
							$order->update_meta_data( '_ams_wc_points_rewards_discount_amount', $reward_amount );
							$order->save();							

						}
					}
				}
				// If Reward point plugin is enabled #################

				if ( ! empty( $request->get_param( 'coupon_lines' ) ) ) {
					foreach ( $request->get_param( 'coupon_lines' ) as $item ) {
						if ( is_array( $item ) ) {
							if ( isset( $item['id'] ) ) {
								if ( ! isset( $item['code'] ) ) {
									throw new WC_REST_Exception( 'woocommerce_rest_invalid_coupon', __( 'Coupon code is required.', 'woocommerce' ), 400 );
								}
								$order   = wc_get_order( $response->data['id'] );
								$results = $order->apply_coupon( wc_clean( $item['code'] ) );

								if ( is_wp_error( $results ) ) {
									throw new WC_REST_Exception( 'woocommerce_rest_' . $results->get_error_code(), $results->get_error_message(), 400 );
								}
								return $response;
							}
						}
					}
				}
				
				//get order payment title set by the user
				$payment_method_id = $object->get_payment_method();
				$payment_gateway_title = '';
				if (!empty($payment_method_id)) {
					$payment_gateways = WC()->payment_gateways->payment_gateways();
					if (isset($payment_gateways[$payment_method_id])) {
						$payment_gateway_title = $payment_gateways[$payment_method_id]->get_title();
					}
				}
				$response->data['ams_payment_method_title'] =  $payment_gateway_title  ;
				
				// this section is to add extra field into order api
				$ams_order_checkout_url                       = ( $value = esc_url( $object->get_checkout_payment_url() ) ) ? $value : '';
				$response->data['order_checkout_payment_url'] = html_entity_decode( $ams_order_checkout_url );
				if ( ! empty( $response->data['line_items'] ) ) {			
					foreach ( $response->data['line_items'] as $key => $lineItem ) {
						$product_id = $lineItem['product_id'];
						$medium_url = wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'thumbnail' );
						$response->data['line_items'][ $key ]['ams_order_thumbnail'] = $medium_url[0];
					}
				}

					return $response;

			}

		public function ams_ls_catalog_hidden_products_search_query_fix( $query = false ) {
			if ( ! is_admin() && isset( $query->query['post_type'] ) && $query->query['post_type'] === 'product' ) {
				$tax_query = $query->get( 'tax_query' );		
				if(!is_array($tax_query)){$tax_query=[];}
				array_push($tax_query,[
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => 'exclude-from-catalog',
						'operator' => 'NOT IN',
					]);
				array_push($tax_query,[
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => 'exclude-from-catalog',
						'operator' => '!=',
					]);
				$tax_query['relation']='AND';					
				$query->set( 'tax_query', $tax_query );
			}
		}
			
		public function ams_ls_create_virtual_coupon( $false, $data ) {

			// Do not interfere with coupon creation and editing.
			if ( is_admin() ) {
				return $false;
			}
				$coupon_code_valid = false;
				$coupon_settings   = null;
				$coupon_amount     = 0;
			if ( $this->ams_is_coupon_code_valid( $data ) ) {
				$coupon_code_valid = true;
			}
			if ( ( $pos = strpos( $data, '@' ) ) !== false ) {
				$coupon_amount = (float) substr( $data, $pos + 1 );
			}
			// Create a coupon with the properties you need
			if ( $coupon_code_valid ) {
				$coupon_settings = array(
					'id'                         => true,
					'discount_type'              => 'fixed_cart', // 'fixed_cart', 'percent' or 'fixed_product'
					'amount'                     => $coupon_amount, // value or percentage.
					'expiry_date'                => date( 'Y-m-d', strtotime( 'tomorrow' ) ), // YYYY-MM-DD
					'individual_use'             => false,
					'product_ids'                => array(),
					'exclude_product_ids'        => array(),
					'usage_limit'                => '1',
					'usage_limit_per_user'       => '1',
					'limit_usage_to_x_items'     => '',
					'usage_count'                => '',
					'free_shipping'              => false,
					'product_categories'         => array(),
					'exclude_product_categories' => array(),
					'exclude_sale_items'         => false,
					'minimum_amount'             => '',
					'maximum_amount'             => '',
					'customer_email'             => array(),
				);

				return $coupon_settings;
			} else {
				return false;
			}

		}
	
		public function ams_ls_order_processing( $order_id ) {

			if ( in_array(
				'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php',
				apply_filters( 'active_plugins', get_option( 'active_plugins' ) )
			)
			) { 
				$order       = wc_get_order( $order_id );
				// order-redeem
				$already_redeemed  = $order->get_meta('_wc_points_redeemed', true);	//get_post_meta( $order_id, '_wc_points_redeemed', true );		//HPOS
				$logged_redemption = $order->get_meta('_wc_points_logged_redemption', true);	//get_post_meta( $order_id, '_wc_points_logged_redemption', true );		//HPOS

				// Points has already been redeemed
				if ( ! empty( $already_redeemed ) ) {
					return;
				}
				
				$customer_id = version_compare( WC_VERSION, '3.0', '<' ) ? $order->user_id : $order->get_user_id();

				// bail for guest user
				if ( ! $customer_id ) {
					return;
				}

				$discount_code = $order->get_meta( '_ams_wc_points_rewards_discount_code' );

				if ( ! empty( $logged_redemption ) ) {
					$points_redeemed = $logged_redemption['points'];
					$discount_amount = $logged_redemption['amount'];
					$discount_code   = $logged_redemption['discount_code'];
				} else {
					$points_redeemed = $order->get_meta( '_ams_wc_points_redeemed' );
					// bail if ams is not involved
					if ( ! $points_redeemed ) {
						return;
					}
					// Get amount of discount
					$discount_amount = $order->get_meta( '_ams_wc_points_rewards_discount_amount' );

				}
				WC_Points_Rewards_Manager::decrease_points(
					$customer_id,
					$points_redeemed,
					'order-redeem',
					array(
						'discount_code'   => $discount_code,
						'discount_amount' => $discount_amount,
					),
					$order_id
				);
				//update_post_meta( $order_id, '_wc_points_redeemed', $points_redeemed );
				$order->update_meta_data('_wc_points_redeemed', $points_redeemed);		//HPOS
				/*update_post_meta(
					$order_id,
					'_wc_points_logged_redemption',
					array(
						'points'        => $points_redeemed,
						'amount'        => $discount_amount,
						'discount_code' => $discount_code,
					)
				);*/
				$order->update_meta_data(		//HPOS
				'_wc_points_logged_redemption',
					array(
						'points'        => $points_redeemed,
						'amount'        => $discount_amount,
						'discount_code' => $discount_code,
					)
				);

				// add order note
				/* translators: 1: points earned 2: points label 3: discount amount */
				$order->add_order_note( sprintf( __( '%1$d %2$s redeemed for a %3$s discount.', 'woocommerce-points-and-rewards' ), $points_redeemed, $this->ams_get_points_label( $points_redeemed ), wc_price( $discount_amount ) ) );
				
				$order->save();		//HPOS

			}

		}
		
		public function ams_get_points_rewards_discount_amount( $customer_id, $wc_points_rewards_discount_amount, $line_items ) {

			if ( empty( $line_items ) ) {
				return 0;
			}
			
			$granted_user_discount = 0;
			// Construct local cart
			if ( is_null( WC()->cart ) ) {
				wc_load_cart();
			}
			WC()->cart->empty_cart();
			
			foreach ( $line_items as $key => $value ) {
				if ( array_key_exists( 'variation_id', $value ) ) {
					WC()->cart->add_to_cart( $value['product_id'], $value['quantity'], $value['variation_id'] );
				} else {
					WC()->cart->add_to_cart( $value['product_id'], $value['quantity'] );
				}
			}

				$available_user_discount = WC_Points_Rewards_Manager::get_users_points_value( $customer_id );

				// no discount
			if ( $available_user_discount <= 0 ) {
				return 0;
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
				return 0;
			}
				$discount_applied = 0;

			if ( ! did_action( 'woocommerce_before_calculate_totals' ) ) {
				WC()->cart->calculate_totals();
			}

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

				// add the discount to the amount to be applied
				$discount_applied += $discount;

				// reduce the remaining discount available to be applied
				$available_user_discount -= $discount;
			}
				// limit the discount available by the global maximum discount if set
				$max_discount = get_option( 'wc_points_rewards_cart_max_discount' );

			if ( false !== strpos( $max_discount, '%' ) ) {
				$max_discount = $this->ams_calculate_discount_modifier( $max_discount );
				WC()->cart->empty_cart();
			}
				$max_discount = filter_var( $max_discount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );

			if ( $max_discount && $max_discount < $discount_applied ) {
				$discount_applied = $max_discount;
			}
				// clear cart before returning
				WC()->cart->empty_cart();
				$discount_applied = filter_var( $discount_applied, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION );
				return $discount_applied;

		}

		public function ams_get_points_label( $count ) {

			list( $singular, $plural ) = explode( ':', get_option( 'wc_points_rewards_points_label' ) );

			return 1 == $count ? $singular : $plural;
		}
		
		public function ams_is_coupon_code_valid( $coupon_code ) {
			if ( 0 === strpos( $coupon_code, 'wc_points_redemption_' ) ) {
				return true;
			}
			return false;
		}

		public function ams_calculate_discount_modifier( $percentage ) {

			$percentage = str_replace( '%', '', $percentage ) / 100;

			if ( 'no' === get_option( 'woocommerce_prices_include_tax' ) ) {
				$discount = WC()->cart->subtotal_ex_tax;

			} else {
				$discount = WC()->cart->subtotal;

			}

			return $percentage * $discount;
		}

	
	}

}

