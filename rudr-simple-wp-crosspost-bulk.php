<?php
/*
 * Plugin name: Simple WP Crossposting – Bulk Actions
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost multiple WooCommerce products at once.
 * Plugin URI: https://rudrastyh.com/support/bulk-crossposting
 * Version: 4.3
 */

class Rudr_WP_Crosspost_Bulk{

	const PER_TICK = 10;

	function __construct(){
		add_action( 'admin_init', array( $this, 'init' ), 999 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		// cron
		add_action( 'rudr_swc_bulk', array( $this, 'run_cron' ), 10, 2 );
		add_filter( 'cron_schedules', array( $this, 'cron_intervals' ) );
		add_action( 'admin_footer', array( $this, 'js' ) );
	}

	// bulk action hooks
	public function init(){

		// do absolutely nothing is the main plugin is not activated
		if( ! class_exists( 'Rudr_Simple_WP_Crosspost' ) ) {
			return;
		}

		$post_types = Rudr_Simple_WP_Crosspost::get_allowed_post_types();
		if( $post_types ) {
			foreach( $post_types as $post_type ) {
				add_filter( 'bulk_actions-edit-' . $post_type, array( $this, 'bulk_action' ) );
				add_filter( 'handle_bulk_actions-edit-' . $post_type, array( $this, 'do_bulk_action' ), 10, 3 );
			}
		}

	}

	// display the bulk actions
	public function bulk_action( $bulk_actions ) {

		$blogs = Rudr_Simple_WP_Crosspost::get_blogs();
		// no blogs are added in the plugin settings or something
		if( $blogs ) {

			// hook https://rudrastyh.com/support/simple-wordpress-crossposting-hook-reference#rudr_swc_use_domains_as_names
			$use_domains = apply_filters( 'rudr_swc_use_domains_as_names', false );

			foreach( $blogs as $blog ) {
				$blogname = ( $use_domains || ! $blog[ 'name' ] ) ? str_replace( array( 'http://', 'https://' ), '', $blog[ 'url' ] ) : $blog[ 'name' ];
				$bulk_actions[ 'crosspost_to_'. Rudr_Simple_WP_Crosspost::get_blog_id( $blog ) ] = "Crosspost to {$blogname}";
			}
		}

		return $bulk_actions;

	}


	// run the actions
	public function do_bulk_action( $redirect, $doaction, $object_ids ){

		set_time_limit(300);

		// first, remove errors query args
		$redirect = remove_query_arg( array( 'swc_crossposted', 'swc_errno' ), $redirect );

		// bulk action check
		if( 'crosspost_to_' !== substr( $doaction, 0, 13 ) ) {
			return $redirect;
		}

		// get a post type
		$screen = get_current_screen();
		$post_type = ! empty( $screen->post_type ) ? $screen->post_type : false;
		// just in case
		if( ! $post_type ) {
			return $redirect;
		}

		// extract blog ID from bulk action
		$blog_id = str_replace( 'crosspost_to_', '', $doaction );

		// depending on how many objects have been selected we may additionally run a cron task
		// 10 objects per iteration seems pretty safe, depends of course, but for the majority
		if( count( $object_ids ) > self::PER_TICK ) {
			$this->start_cron( $object_ids, $blog_id, $post_type );
		}

		$this->do_bulk( array_slice( $object_ids, 0, self::PER_TICK ), $blog_id, $post_type );

		// simepl – here we just redirect to a success message
		// errors are going to be added when we doing the crossposting
		// whether we are using cron or not we decide in admin_notices already
		return add_query_arg( array( 'swc_crossposted' => count( $object_ids ) ), $redirect );

	}


	// doing the bulk
	private function do_bulk( $object_ids, $blog_id, $post_type ) {
//return;
		if( Rudr_Simple_WP_Crosspost::is_woocommerce() && 'product' === $post_type ) {
			$this->bulk_products( $object_ids, $blog_id );
		} else {
			$this->bulk_posts( $object_ids, $blog_id, $post_type );
		}

	}

	// posts only
	private function bulk_posts( $object_ids, $blog_id, $post_type ) {

		if( ! class_exists( 'Rudr_Simple_WP_Crosspost' ) ) {
			return;
		}

		// blog information
		$blog = Rudr_Simple_WP_Crosspost::get_blog( $blog_id );

		// post type information (for rest base)
		$post_type_object = get_post_type_object( $post_type );
		$rest_base = $post_type_object->rest_base ? $post_type_object->rest_base : $post_type;

		// our request body is going to be here
		$body = array(
			'requests' => array()
		);

		foreach( $object_ids as $object_id ) {

			$post = get_post( $object_id );
			if( ! $post ) {
				continue;
			}

			// start creating our request
			$request = array(
				'method' => 'POST',
			);

			// check if this post is already crossposted
			if( $crossposted_post_id = Rudr_Simple_WP_Crosspost::is_crossposted( $object_id, $blog_id ) ) {
				$request[ 'path' ] = "/wp/v2/{$rest_base}/{$crossposted_post_id}";
				$action = 'update';
			} else {
				$request[ 'path' ] = "/wp/v2/{$rest_base}";
				$action = 'create';
			}

			$post_data = array(
				'date' => $post->post_date,
				'date_gmt' => $post->post_date_gmt,
				'modified' => $post->post_modified,
				'modified_gmt' => $post->post_modified_gmt,
				'slug' => $post->post_name,
				'status' => $post->post_status,
				'title' => $post->post_title,
				'type' => $post->post_type,
				'content' => $post->post_content,
				'parent' => $post->post_parent,
				'excerpt' => $post->post_excerpt,
				'password' => $post->post_password,
				'template' => get_page_template_slug( $post ),
			);

			// exclude some fields
			$excluded_fields = get_option( 'rudr_sac_excluded_fields', array() );
			foreach( $excluded_fields as $key ) {
				if( array_key_exists( $key, $post_data ) ) {
					unset( $post_data[ $key ] );
				}
			}

			// meta data
			if( ! in_array( 'meta', $excluded_fields ) ) {
				$post_data = Rudr_Simple_WP_Crosspost::add_meta( $post_data, $post, $blog );
			}

			if( ! in_array( 'terms', $excluded_fields ) ) {
				$post_data = Rudr_Simple_WP_Crosspost::add_terms( $post_data, $post, $blog );
			}

			if( ! in_array( 'thumbnail', $excluded_fields ) ) {
				$post_data = Rudr_Simple_WP_Crosspost::add_featured_image( $post_data, $post, $blog );
			}

			if( isset( $post_data[ 'parent' ] ) && $post_data[ 'parent' ] ) {
				$post_data[ 'parent' ] = Rudr_Simple_WP_Crosspost::is_crossposted( $post_data[ 'parent' ], $blog_id );
			}

			$request[ 'body' ] = apply_filters( 'rudr_swc_pre_crosspost_post_data', $post_data, $blog, $post, $action );
			$body[ 'requests' ][] = $request;

		}

		$request = wp_remote_request(
			"{$blog[ 'url' ]}/wp-json/batch/v1/",
			array(
				'method' => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
				),
				'body' => $body
			)
		);

		if( 207 === wp_remote_retrieve_response_code( $request ) ) {

			$body = json_decode( wp_remote_retrieve_body( $request ), true );
			$batch_responses = isset( $body[ 'responses' ] ) ? $body[ 'responses' ] : array();

			if( $batch_responses ) {
				for( $i = 0; $i < count( $batch_responses ); $i++ ) {
					// if product ID is empty then it means an error occured
					if( empty( $batch_responses[ $i ][ 'body' ][ 'id' ] ) ) {
						continue;
					}
					// add crossposted data if everything is great
					Rudr_Simple_WP_Crosspost::add_crossposted_data(
						$object_ids[ $i ],
						$batch_responses[ $i ][ 'body' ][ 'id' ],
						$blog_id
					);
					update_post_meta( $object_ids[ $i ], Rudr_Simple_WP_Crosspost::META_KEY . $blog_id, 1 );
				}

			}

		}

		$this->process_errors( $request, $post_type );

	}

	// WooCommerce products only
	private function bulk_products( $object_ids, $blog_id ) {

		if( ! class_exists( 'Rudr_Simple_WP_Crosspost' ) ) {
			return;
		}

		if( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$excluded = get_option( 'rudr_sac_excluded_woo_fields', array() );
		$excluded_post_fields = get_option( 'rudr_sac_excluded_fields', array() );
		$update_mode = ( 'yes' === get_option( 'rudr_sac_update_mode' ) ) ? true : false;

		// we need full blog information
		$blog = Rudr_Simple_WP_Crosspost::get_blog( $blog_id );
		// our request body is going to be here
		$body = array(
			'create' => array(),
			'update' => array(),
		);
		// we need it in order to store crossposted data
		$products_to_create = array();
		$products_to_update = array();

		foreach( $object_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			// just in case
			if( ! $product ) {
				continue;
			}

			// 1. collect data
			$product_data = array(
				'name'              => $product->get_title(),
				'description'       => $product->get_description(),
				'short_description' => $product->get_short_description(),
				'status'            => $product->get_status(),
				'type'              => $product->get_type(),
				'sold_individually' => $product->get_sold_individually(),
				'purchase_note'			=> $product->get_purchase_note(),
				'menu_order'        => (int) $product->get_menu_order(),
				'reviews_allowed'   => $product->get_reviews_allowed(),
				'catalog_visibility' => $product->get_catalog_visibility(),
				'featured'           => $product->get_featured(),
			);

			$product_data = Rudr_Simple_Woo_Crosspost::add_prices( $product_data, $product );
			$product_data = Rudr_Simple_Woo_Crosspost::add_stock_and_shipping_info( $product_data, $product );
			$product_data = Rudr_Simple_Woo_Crosspost::add_downloads( $product_data, $product );

			// we are cleaning it up in a different place
			$product_data = Rudr_Simple_Woo_Crosspost::clean_excluded_fields( $product_data, $excluded );

			// blog-related fields are going to be next
			$product_data = Rudr_Simple_Woo_Crosspost::add_images( $product_data, $product, $blog, $excluded );
			$product_data = Rudr_Simple_Woo_Crosspost::add_attributes( $product_data, $product, $blog, $excluded );
			$product_data = Rudr_Simple_Woo_Crosspost::add_linked_products( $product_data, $product, $blog, $excluded );

			// we need meta here because of attachment filter hooks
			if( ! in_array( 'meta', $excluded_post_fields ) ) {
				$product_data = Rudr_Simple_Woo_Crosspost::add_meta_data( $product_data, $product, $blog );
			}

			if( ! in_array( 'tag_ids', $excluded ) ) {
				$product_data[ 'tags' ] = Rudr_Simple_WP_Crosspost::get_synced_term_ids( $product->get_id(), 'product_tag', $blog );
				$product_data[ 'tags' ] = apply_filters( 'rudr_swc_terms', $product_data[ 'tags' ], $product->get_id(), 'product_tag', $blog );
			}
			if( ! in_array( 'category_ids', $excluded ) ) {
				$product_data[ 'categories' ] = Rudr_Simple_WP_Crosspost::get_synced_term_ids( $product->get_id(), 'product_cat', $blog );
				$product_data[ 'categories' ] = apply_filters( 'rudr_swc_terms', $product_data[ 'categories' ], $product->get_id(), 'product_cat', $blog );
			}


			// 2. Add to request body
			if( $id = Rudr_Simple_Woo_Crosspost::is_crossposted_product( $product, $blog ) ) {
				$product_data[ 'id' ] = $id;
				$body[ 'update' ][] = apply_filters( 'rudr_swc_pre_crosspost_product_data', $product_data, $blog, $product, 'update' );
				$products_to_update[] = $product;
				// super cool story here is that we can update product variations at this step, we have everything for it
				if( ! in_array( 'variations', $excluded ) ) {
					Rudr_Simple_Woo_Crosspost::add_product_variations( $id, $product, $blog );
				}

			} else {
				unset( $product_data[ 'id' ] );
				$body[ 'create' ][] = apply_filters( 'rudr_swc_pre_crosspost_product_data', $product_data, $blog, $product, 'create' );
				$products_to_create[] = $product; // array of product objects
			}

		}

		// 3. let's make a request
		$request = wp_remote_post(
			$blog[ 'url' ] . '/wp-json/wc/v3/products/batch',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
				),
				'body' => $body
			)
		);

		if( 'OK' === wp_remote_retrieve_response_message( $request ) ) {
			$products = json_decode( wp_remote_retrieve_body( $request ), true );

			// connections, variations for new products
			// for updated products we sync variation in another place!
			if( isset( $products[ 'create' ] ) && is_array( $products[ 'create' ] ) ) {
				for( $i = 0; $i < count( $products[ 'create' ] ); $i++ ) {

					// for new products we need to set a connection but only if they are not connected by SKU!
					if( 'sku' !== Rudr_Simple_Woo_Crosspost::connection_type() ) {
						Rudr_Simple_WP_Crosspost::add_crossposted_data(
							$products_to_create[ $i ]->get_id(),
							$products[ 'create' ][ $i ][ 'id' ],
							$blog_id
						);
					}
					update_post_meta( $products_to_create[$i]->get_id(), Rudr_Simple_WP_Crosspost::META_KEY . $blog_id, true );
					if( ! in_array( 'variations', $excluded ) ) {
						// rudr_swc_pre_crosspost_variation_data is in this method
						Rudr_Simple_Woo_Crosspost::add_product_variations( $products[ 'create' ][ $i ][ 'id' ], $products_to_create[$i], $blog );
					}
				}
			}
			// let's check the checkbox for updated products as well
			if( isset( $products[ 'update' ] ) && is_array( $products[ 'update' ] ) ) {
				for( $i = 0; $i < count( $products[ 'update' ] ); $i++ ) {
					update_post_meta( $products_to_update[$i]->get_id(), Rudr_Simple_WP_Crosspost::META_KEY . $blog_id, true );
				}
			}

		}

		$this->process_errors( $request, 'product' );

	}


	public function cron_intervals( $intervals ) {

		$intervals[ 'swc_min' ] = array(
			'interval' => 60,
			'display' => 'Every min (Simple WP Crossposting)'
		);
		return $intervals;

	}

	// starting the cron job
	private function start_cron( $object_ids, $blog_id, $post_type ) {

		if( ! wp_next_scheduled( 'rudr_swc_bulk', array( $blog_id, $post_type ) ) ) {
			wp_schedule_event( time() + 30, 'swc_min', 'rudr_swc_bulk', array( $blog_id, $post_type ) );
		} else {
			// TODO maybe we can display some error messages
			return;
		}

		update_option( "rudr_swc_bulk_{$post_type}_ids_total", $object_ids );
		update_option( "rudr_swc_bulk_{$post_type}_ids", array_slice( $object_ids, self::PER_TICK ) );
		delete_option( "rudr_swc_bulk_{$post_type}_errors" );
		delete_option( "rudr_swc_bulk_{$post_type}_finished" );

	}

	// doing the cron job iteration
	public function run_cron( $blog_id, $post_type ) {

		// get remaining object IDs first
		$object_ids = get_option( "rudr_swc_bulk_{$post_type}_ids" );

		if( $object_ids ) {
			// run sync
			$this->do_bulk( array_slice( $object_ids, 0, self::PER_TICK ), $blog_id, $post_type );

			if( count( $object_ids ) > self::PER_TICK ) {
				// remove first 10 objects
				$object_ids = array_slice( $object_ids, self::PER_TICK );
				// update option
				update_option( "rudr_swc_bulk_{$post_type}_ids", $object_ids );
				return;
			}
		}

		delete_option( "rudr_swc_bulk_{$post_type}_ids" );
		update_option( "rudr_swc_bulk_{$post_type}_finished", 'yes' );
		// unschedule cron
		wp_clear_scheduled_hook( 'rudr_swc_bulk', array( $blog_id, $post_type ) );

	}



	private function process_errors( $request, $post_type ) {
		//file_put_contents( __DIR__ . '/log.txt' , print_r( $request, true ) );
		$errors = get_option( "rudr_swc_bulk_{$post_type}_errors", array() );

		// let's try to process WordPress errors first
		if( 207 === wp_remote_retrieve_response_code( $request ) ) {

			$body = json_decode( wp_remote_retrieve_body( $request ), true );
			$responses = isset( $body[ 'responses' ] ) ? $body[ 'responses' ] : array();

			// using for here in case we make the errors more advanced
			for( $i = 0; $i < count( $responses ); $i++ ) {
				// should be great
				if( ! empty( $responses[ $i ][ 'body' ][ 'id' ] ) ) {
					continue;
				}
				// error? record it and exit the loop
				if( ! empty( $responses[ $i ][ 'body' ][ 'code' ] ) ) {
					// ok we need to add this code to an array
					$code = $responses[ $i ][ 'body' ][ 'code' ];
					// in case we didn't have errors for this error code so far
					if( empty( $errors[ $code ] ) ) {
						$errors[ $code ] = 0;
					}
					// +1 error
					$errors[ $code ]++;

				}
			}

		}

		// processing errors for WooCommerce 200 - OK
		if( 200 === wp_remote_retrieve_response_code( $request ) ) {

			$body = json_decode( wp_remote_retrieve_body( $request ), true );
			$create = isset( $body[ 'create' ] ) ? $body[ 'create' ] : array();
			$update = isset( $body[ 'update' ] ) ? $body[ 'update' ] : array();
			$responses = $create + $update;

			// using for here in case we make the errors more advanced
			for( $i = 0; $i < count( $responses ); $i++ ) {
				// error? record it and exit the loop
				if( ! empty( $responses[ $i ][ 'error' ][ 'code' ] ) ) {
					// ok we need to add this code to an array
					$code = $responses[ $i ][ 'error' ][ 'code' ];
					// in case we didn't have errors for this error code so far
					if( empty( $errors[ $code ] ) ) {
						$errors[ $code ] = 0;
					}
					// +1 error
					$errors[ $code ]++;
				}
			}

		}

		update_option( "rudr_swc_bulk_{$post_type}_errors", $errors );

	}



	public function notices(){

		// get some screen information about post type etc
		$screen = get_current_screen();
		$post_type = ! empty( $screen->post_type ) ? $screen->post_type : false;
		// something is not right here
		if( 'edit' !== $screen->base || ! $post_type ) {
			return;
		}

		if( ! class_exists( 'Rudr_Simple_WP_Crosspost' ) ) {
			return;
		}

		$display_notices = true;
		$display_errors = false;

		// seems like we need to loop all the blogs and check whhether a cron job is running
		$blogs = Rudr_Simple_WP_Crosspost::get_blogs();
		// if there is no blogs added, nothing else to do here anyway
		if( ! $blogs ) {
			return;
		}

		// post type object will be useful
		$post_type_object = get_post_type_object( $post_type );
		// we are going to display blog names in some notifications
		$use_domains = apply_filters( 'rudr_swc_use_domains_as_names', false );

		// Schedule action message
		foreach( $blogs as $blog ) {
			$blog_id = Rudr_Simple_WP_Crosspost::get_blog_id( $blog );
			$blogname = ( $use_domains || ! $blog[ 'name' ] ) ? str_replace( array( 'http://', 'https://' ), '', $blog[ 'url' ] ) : $blog[ 'name' ];

			if( wp_next_scheduled( 'rudr_swc_bulk', array( $blog_id, $post_type ) ) ) {
				$display_notices = false;
				?><div class="notice-info notice swc-bulk-notice--in-progress"><p><?php echo esc_html( sprintf( '%s are currently being crossposted to %s in the background. It may take some time depending on how many %s you have selected.', $post_type_object->label, $blogname, mb_strtolower( $post_type_object->label ) ) ) ?></p></div><?php
			}
		}

		// get the amount of error messages we encountered
		//update_option( 'rudr_swc_bulk_errors', array( 'rest_post_invalid_id' => 2 ) );

		$errors = get_option( "rudr_swc_bulk_{$post_type}_errors", array() );
		if( 'product' === $post_type ) {
			// WooCommerce
			$error_1_count = ! empty( $errors[ 'woocommerce_rest_product_invalid_id' ] ) ? (int) $errors[ 'woocommerce_rest_product_invalid_id' ] : 0;
			$error_2_count = ! empty( $errors[ 'woocommerce_product_invalid_image_id' ] ) ? (int) $errors[ 'woocommerce_product_invalid_image_id' ] : 0;
			$total_errors = $error_1_count + $error_2_count;
		} else {
			// other post types
			$error_1_count = ! empty( $errors[ 'rest_post_invalid_id' ] ) ? (int) $errors[ 'rest_post_invalid_id' ] : 0;
			$total_errors = $error_1_count;
		}

		// Success message when less than 10 products selected
		$object_ids = isset( $_REQUEST[ 'swc_crossposted' ] ) && $_REQUEST[ 'swc_crossposted' ] ? absint( $_REQUEST[ 'swc_crossposted' ] ) : 0;
		if( $display_notices ) {
			if( $object_ids && $object_ids <= self::PER_TICK ) {
				// remove error numbers
				$display_errors = true;
				$object_ids = $object_ids - $total_errors;
				if( $object_ids > 0 ) {
					// display success message because at least one item has been crossposted – great!
					?><div class="updated notice is-dismissible"><p><?php
					echo esc_html( sprintf(
						'' . _n( '%d %s has been successfully crossposted.', '%d %s have been successfully crossposted.', $object_ids ),
						$object_ids,
						mb_strtolower( $object_ids > 1 ? $post_type_object->label : $post_type_object->labels->singular_name )
					) );
					?></p></div><?php

				}
			} elseif( 'yes' == get_option( "rudr_swc_bulk_{$post_type}_finished" ) ) {
				delete_option( "rudr_swc_bulk_{$post_type}_finished" );

				$display_errors = true;
				$object_ids = get_option( "rudr_swc_bulk_{$post_type}_ids_total", array() );
				$object_ids = is_array( $object_ids ) ? count( $object_ids ) : 0;
				$object_ids = $object_ids - $total_errors;
				// the same
				if( $object_ids > 0 ) {
					?><div class="updated notice is-dismissible"><p><?php
					echo esc_html( sprintf(
						_n( '%d %s has been successfully crossposted.', '%d %s have been successfully crossposted.', $object_ids ),
						$object_ids,
						mb_strtolower( $object_ids > 1 ? $post_type_object->label : $post_type_object->labels->singular_name )
					) );
					?></p></div><?php
				}

			}
		}

		if( $display_errors ) {
			// Display error message for error 1
			if( isset( $error_1_count ) && $error_1_count ) {
				?><div class="notice-warning notice is-dismissible"><p><?php
				echo esc_html( sprintf(
					_n( '%d %s hasn\'t been crossposted because its copy on another site was removed manually.', '%d %s haven\'t been crossposted because their copies on another site were removed manually.', $error_1_count ),
					$error_1_count,
					mb_strtolower( $error_1_count > 1 ? $post_type_object->label : $post_type_object->labels->singular_name )
				) );
				?></p></div><?php
			}

			// Display error message for error 2
			if( isset( $error_2_count ) && $error_2_count ) {
				?><div class="notice-warning notice is-dismissible"><p><?php
				echo esc_html( sprintf(
					_n( '%d %s hasn\'t been crossposted because its images on another site were removed manually.', '%d %s haven\'t been crossposted because their images on another site were removed manually.', $error_2_count ),
					$error_2_count,
					mb_strtolower( $error_2_count > 1 ? $post_type_object->label : $post_type_object->labels->singular_name )
				) );
				?></p></div><?php
			}

			delete_option( "rudr_swc_bulk_{$post_type}_errors" );
		}


	}

	// JS check, the beautiful way
	public function js() {
		?><script>
		jQuery( function( $ ) {
			if( $( '.swc-bulk-notice--in-progress' ).length > 0 ) {
				$( '#bulk-action-selector-top option, #bulk-action-selector-bottom option' ).each( function() {
					if( $(this).val().startsWith( 'crosspost_to_' ) ) {
						$(this).prop( 'disabled', true );
					}
				} );
			}
		});
		</script><?php
	}

}
new Rudr_WP_Crosspost_Bulk;
