<?php
/*
 * Plugin name: Simple WP Crossposting – Bulk Actions
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost multiple WooCommerce products at once.
 * Version: 3.1
 */

class Rudr_WP_Crosspost_Bulk{

	const LIMIT = 20;

	function __construct(){
		add_action( 'admin_init', array( $this, 'init' ), 999 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	// bulk action hooks
	public function init(){

		// we get and filter post types the same way we do it in the plugin itself
		$post_types = get_post_types( array( 'public' => true ) );
		$allowed_post_types = get_option( 'rudr_sac_post_types', array() );
		$post_types = $allowed_post_types && is_array( $allowed_post_types ) ? array_intersect( $post_types, $allowed_post_types ) : $post_types;
		if( ( $key = array_search( 'attachment', $post_types ) ) !== false) {
			unset( $post_types[ $key ] );
		}

		if( $post_types ) {
			foreach( $post_types as $post_type ) {
				add_filter( 'bulk_actions-edit-' . $post_type, array( $this, 'bulk_action' ) );
				add_filter( 'handle_bulk_actions-edit-' . $post_type, array( $this, 'do_bulk_action' ), 10, 3 );
			}
		}

	}

	// display the bulk actions
	public function bulk_action( $bulk_actions ) {

		if( class_exists( 'Rudr_Simple_WP_Crosspost' ) && ( $blogs = Rudr_Simple_WP_Crosspost::get_blogs() ) ) {

			// some tricks with blog name are going to be here
			// first, is the hook in use?
			$use_domains = apply_filters( 'rudr_crosspost_use_domains_as_names', false );

			foreach( $blogs as $blog ) {

				// second, we should use URL when blog name isn't provided as well
				$blogname = ( $use_domains || ! $blog[ 'name' ] ) ? str_replace( array( 'http://', 'https://' ), '', $blog[ 'url' ] ) : $blog[ 'name' ];
				$bulk_actions[ 'crosspost_to_'. Rudr_Simple_WP_Crosspost::get_blog_id( $blog ) ] = "Crosspost to {$blogname}";
			}
		}

		return $bulk_actions;

	}


	// and now the most interesting part
	public function do_bulk_action( $redirect, $doaction, $object_ids ){

		set_time_limit(300);

		// first, remove errors query args
		$redirect = remove_query_arg(
			array(
				'swc_crossposted',
				'swc_errno'
			),
			$redirect
		);

		// we do nothing if it is not our bulk action
		if( 'crosspost_to_' !== substr( $doaction, 0, 13 ) ) {
			return $redirect;
		}

		// additionally check for plugin just in case
		if( ! class_exists( 'Rudr_Simple_WP_Crosspost' ) ) {
			return $redirect;
		}



		// let's figure it out post type now and do nothing if we can not
		$screen = get_current_screen();
		$post_type = ! empty( $screen->post_type ) ? $screen->post_type : false;
		if( ! $post_type ) {
			return $redirect;
		}

		// extract blog ID from bulk action
		$blog_id = str_replace( 'crosspost_to_', '', $doaction );

		// oops, we have some limits here as well
		if( count( $object_ids ) > self::LIMIT ) {
			return add_query_arg( 'swc_errno', 'bulk_limit_exceeded', $redirect );
		}

		if( Rudr_Simple_WP_Crosspost::is_woocommerce() && 'product' === $post_type ) {
			$res = $this->bulk_products( $object_ids, $blog_id );
		} else {
			$res = $this->bulk_posts( $object_ids, $blog_id, $post_type );
		}

		// redirect to success
		return $this->process_errors( $res, $redirect, $object_ids );

	}


	private function bulk_posts( $object_ids, $blog_id, $post_type ) {

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
			} else {
				$request[ 'path' ] = "/wp/v2/{$rest_base}";
			}

			$request[ 'body' ] = array(
				'date' => $post->post_date,
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
				if( array_key_exists( $key, $body_data ) ) {
					unset( $body_data[ $key ] );
				}
			}

			// meta data
			if( ! in_array( 'meta', $excluded_fields ) ) {
				$request[ 'body' ] = Rudr_Simple_WP_Crosspost::add_meta( $request[ 'body' ], $post, $blog );
			}

			if( ! in_array( 'terms', $excluded_fields ) ) {
				$request[ 'body' ] = Rudr_Simple_WP_Crosspost::add_terms( $request[ 'body' ], $post, $blog );
			}

			if( ! in_array( 'thumbnail', $excluded_fields ) ) {
				$request[ 'body' ] = Rudr_Simple_WP_Crosspost::add_featured_image( $request[ 'body' ], $post, $blog );
			}

			if( isset( $request[ 'body' ][ 'parent' ] ) && $request[ 'body' ][ 'parent' ] ) {
				$request[ 'body' ][ 'parent' ] = Rudr_Simple_WP_Crosspost::is_crossposted( $request[ 'body' ][ 'parent' ], $blog_id );
			}

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
				}

			}

		}

		return $request;

	}


	// Bulk crosspost products (it gets more interesting)
	private function bulk_products( $object_ids, $blog_id ) {

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
				$body[ 'update' ][] = $product_data;
				// super cool story here is that we can update product variations at this step, we have everything for it
				Rudr_Simple_Woo_Crosspost::add_product_variations( $id, $product, $blog );

			} else {
				unset( $product_data[ 'id' ] );
				$body[ 'create' ][] = $product_data;
				$products_to_create[] = $product; // array of product objects
			}

		}

		// 3. let's make a request
		$request = wp_remote_post(
			$blog[ 'url' ] . '/wp-json/wc/v3/products/batch',
			array(
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
					Rudr_Simple_Woo_Crosspost::add_product_variations( $products[ 'create' ][ $i ][ 'id' ], $products_to_create[$i], $blog );
				}
			}

		}

		return $request;

	}

	private function process_errors( $res, $redirect, $object_ids ) {

		// we need to get a response code which is going to be sent as ?errno=
		$errno = false;

		// let's try to process WordPress errors first
		if( 207 === wp_remote_retrieve_response_code( $res ) ) {

			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$responses = isset( $body[ 'responses' ] ) ? $body[ 'responses' ] : array();

			// using for here in case we make the errors more advanced
			for( $i = 0; $i < count( $responses ); $i++ ) {
				// should be great
				if( ! empty( $responses[ $i ][ 'body' ][ 'id' ] ) ) {
					continue;
				}
				// error? record it and exit the loop
				if( ! empty( $responses[ $i ][ 'body' ][ 'code' ] ) ) {
					$errno = $responses[ $i ][ 'body' ][ 'code' ];
					break;
				}
			}

		}

		// processing errors for WooCommerce 200 - OK
		if( 200 === wp_remote_retrieve_response_code( $res ) ) {

			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$create = isset( $body[ 'create' ] ) ? $body[ 'create' ] : array();
			$update = isset( $body[ 'update' ] ) ? $body[ 'update' ] : array();
			$responses = $create + $update;

			// using for here in case we make the errors more advanced
			for( $i = 0; $i < count( $responses ); $i++ ) {
				// error? record it and exit the loop
				if( ! empty( $responses[ $i ][ 'error' ][ 'code' ] ) ) {
					$errno = $responses[ $i ][ 'error' ][ 'code' ];
					break;
				}
			}

		}

		if( $errno ) {

			return add_query_arg( array( 'swc_errno' => $errno ), $redirect );

		} else {

			return add_query_arg( array( 'swc_crossposted' => count( $object_ids ) ), $redirect );

		}


	}


	// display some appripriate notices
	public function notices(){

		$screen = get_current_screen();
		if( 'edit' !== $screen->base ) {
			return;
		}

		if( ! empty( $_GET[ 'swc_errno' ] ) ) {

			switch( $_GET[ 'swc_errno' ] ) {

				case 'rest_post_invalid_id' : {
					?><div class="error notice is-dismissible"><p>Some posts haven't been updated because their copies on another website were removed manually.</p></div><?php
					break;
				}

				case 'woocommerce_product_invalid_image_id' : {
					?><div class="error notice is-dismissible"><p>Some products haven't been synced because their images on another website were removed manually. If want to re-upload them, re-publish them individually.</p></div><?php
					break;
				}

				case 'bulk_limit_exceeded' : {
					?><div class="error notice is-dismissible"><p>You have selected to many items. Please select <?php echo self::LIMIT ?> or less.</p></div><?php
					break;
				}

				default: {
					break;
				}

			}

		}

		if( ! empty( $_REQUEST[ 'swc_crossposted' ] ) ) {

			printf( '<div class="updated notice is-dismissible"><p>' .
				_n( '%s item has been successfully synced.', '%s items have been successfully crossposted.', absint( $_REQUEST[ 'swc_crossposted' ] ) )
				. '</p></div>', absint( $_REQUEST[ 'swc_crossposted' ] ) );

		}


	}

}
new Rudr_WP_Crosspost_Bulk;
