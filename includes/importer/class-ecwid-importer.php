<?php

require_once dirname(__FILE__) . '/class-ecwid-importer-task.php';

class Ecwid_Importer
{
	const OPTION_TASKS = 'ecwid_importer_tasks';
	const OPTION_CURRENT_TASK = 'ecwid_importer_current_task';
	const OPTION_CATEGORIES = 'ecwid_importer_categories';
	const OPTION_PRODUCTS = 'ecwid_importer_products'; 
	const OPTION_STATUS = 'ecwid_importer_status';
	const OPTION_WOO_CATALOG_IMPORTED = 'ecwid_imported_from_woo';
	const OPTION_SETTINGS = 'ecwid_importer_settings';
	const OPTION_DEMO_PRODUCTS = 'woo_importer_demo_products';
	
	const TICK_LENGTH = 5;
	
	const SETTING_UPDATE_BY_SKU = 'update-by-sku';
	const SETTING_DELETE_DEMO = 'delete-demo';

	const DEMO_CREATE_FROM = 1469707991;
	const DEMO_CREATE_TO = 1469710442;
	
	protected $_tasks;
	protected $_start_time;
	
	public function initiate( $settings = array() )
	{
		update_option( self::OPTION_CATEGORIES, array() );
		update_option( self::OPTION_PRODUCTS, array() );
		update_option( self::OPTION_TASKS, array() );
		update_option( self::OPTION_STATUS, array() );

		$this->_start();
		
		$api = new Ecwid_Api_V3();

		$this->_set_settings( $settings );
		$this->_maybe_set_forced_settings();
		$this->_build_tasks();
		$this->_set_current_task( 0 );
		
	}
	
	public function tick()
	{
		set_time_limit(0);
		$results = array();
		
		$status = get_option( self::OPTION_STATUS, array( 'plan_limit' => array() ) );
		$count = 0;
		$progress = array( 'success' => array(), 'error' => array(), 'total' => count($this->_tasks) );
		
		do {
			$current_task = $this->_load_current_task();

			$task_data = $this->_tasks[$current_task];
			
			if ( !is_array( $status['plan_limit'] ) || !array_key_exists( $task_data['type'], $status['plan_limit'] ) ) {
				
				$task = Ecwid_Importer_Task::load_task($task_data['type']);
	
				$result = $task->execute($this, $task_data);
				
				if ( $result['status'] == 'error' ) {
					$progress['error'][] = $task_data['type'];
					
					$error_data = $result['data'];
					
					if ( @$error_data['response']['code'] == 402 ) {
						$status['plan_limit'][$task_data['type']] = true;
					}
					
					$message = '';
					if ( is_wp_error( $error_data ) ) {
						$message = $result['data']->get_error_message();
					} elseif ( isset( $error_data['api_message'] ) ) {
						$message = $error_data['api_message'];
					} elseif ( @$error_data == 'skipped' ) {
						$message = $result['message'];
					}
					
					$this->_tasks[$current_task]['error'] = $message;
					
					$progress['error_messages'][$task_data['type']][$message]++;
					error_log( var_export( $result['sent_data'], true ) );
				} else {
					$progress['success'][] = $task_data['type'];
				}

				update_option( self::OPTION_STATUS, $status );
			} else {
				$progress['error'][] = $task_data['type'];
				$progress['plan_limit_hit'][] = $task_data['type'];
			}
			
			$current_task++;
			
			if ( $current_task >= count( $this->_tasks ) ) {
				break;
			}
			
			$this->_set_current_task( $current_task );
			$count++;
			
			$progress['current'] = $current_task;
			
			if ( $count > self::TICK_LENGTH ) {
				$progress['status'] = 'in_progress';
				
				return $progress;
			}
			
		} while ( 1 );

		$this->_set_tasks( null );
		
		$progress['status'] = 'complete';
		
		update_option( self::OPTION_WOO_CATALOG_IMPORTED, 'true' );
		
		return $progress;
	}
	
	public function has_begun()
	{
		return $this->_load_tasks() != null;
	}
	
	public function proceed()
	{
		$this->_load_tasks();
		$this->_load_current_task();
		
		return $this->tick();
	}
	
	public function get_ecwid_category_id( $woo_category_id ) {
		if ( !$woo_category_id ) {
			return 0;
		}
		
		$categories = get_option( self::OPTION_CATEGORIES, array() );
		
		return $categories[$woo_category_id];
	}
	
	public function save_ecwid_category( $woo_category_id, $ecwid_category_id )
	{
		$categories = get_option( self::OPTION_CATEGORIES, array() );

		$categories[$woo_category_id] = $ecwid_category_id;
		
		update_option(self::OPTION_CATEGORIES, $categories );
	}
	
	public function get_ecwid_product_id( $woo_product_id ) {
		$products = get_option( self::OPTION_PRODUCTS, array() );

		return @$products[$woo_product_id];
	}

	public function save_ecwid_product_id( $woo_product_id, $ecwid_product_id )
	{
		$products = get_option( self::OPTION_PRODUCTS, array() );

		$products[$woo_product_id] = $ecwid_product_id;

		update_option(self::OPTION_PRODUCTS, $products );
	}
	
	protected function _start()
	{
		$this->_start_time = time();
	}
	
	protected function _build_tasks()
	{
		$tasks = array();

		if ( $this->get_setting( self::SETTING_DELETE_DEMO ) && self::count_ecwid_demo_products() ) {
			$tasks[] = Ecwid_Importer_Task_Delete_Products::build( self::get_ecwid_demo_products() );
		}

		if ( ecwid_is_paid_account() ) {
			$categories = $this->gather_categories();
			
			foreach ( @$categories as $category ) {
				$tasks[] = Ecwid_Importer_Task_Create_Category::build( $category );
				if ( $category['has_image'] ) {
					$tasks[] = Ecwid_Importer_Task_Upload_Category_Image::build( $category );
				}
			}
		}
	
		$products = $this->gather_products();
		
		foreach ( $products as $product ) {
			$tasks[] = Ecwid_Importer_Task_Create_Product::build( $product );
			
			if ( $product['has_image'] ) {
				$tasks[] = Ecwid_Importer_Task_Upload_Product_Image::build( $product );
			}

			$p = wc_get_product( $product['woo_id'] );

			if ( $p instanceof WC_Product_Variable ) {

				$vars = $p->get_available_variations();
				
				foreach ( $vars as $var ) {
					
					$tasks[] = Ecwid_Importer_Task_Create_Product_Variation::build( 
						array(
							'woo_id' => $product['woo_id'],
							'var_id' => $var['variation_id']
						)
					);
					
					if ( $var['image_id'] != $p->get_image_id() ) {
						$tasks[] = Ecwid_Importer_Task_Upload_Product_Variation_Image::build(
							array(
								'product_id' => $product['woo_id'],
								'variation_id' => $var['variation_id']
							)
						);
					}
				}
			}
			
			if ( $product['gallery_images'] ) {
				foreach ( $product['gallery_images'] as $image ) {
					$tasks[] = Ecwid_Importer_Task_Upload_Product_Gallery_Image::build(
						array(
							'product_id' => $product['woo_id'],
							'image_id' => $image
						)
					);
				}
			}
		}

		$this->_set_tasks($tasks);
	}
	
	protected function _set_tasks( $tasks )
	{
		$this->_tasks = $tasks;
		update_option( self::OPTION_TASKS, $this->_tasks );
	}
	
	protected function _load_tasks()
	{
		return $this->_tasks = get_option( self::OPTION_TASKS, null );
	}
	
	protected function _set_current_task( $ind ) {
		update_option( self::OPTION_CURRENT_TASK, $ind );
	}
	
	protected function _load_current_task() {
		return get_option( self::OPTION_CURRENT_TASK, null );
	}
	
	public function get_setting( $name ) {
		$settings = get_option( self::OPTION_SETTINGS, array() );

		return @$settings[$name];
	}
	
	protected function _set_setting( $name, $value ) {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		
		$settings[$name] = $value;
		
		update_option( self::OPTION_SETTINGS, $settings );
	}
	
	protected function _set_settings( $settings ) {
		$saved_settings = array();
		
		if ( $settings[self::SETTING_UPDATE_BY_SKU] ) {
			$saved_settings[self::SETTING_UPDATE_BY_SKU] = true;
		}
		
		if ( @$settings[self::SETTING_DELETE_DEMO] ) {
			$saved_settings[self::SETTING_DELETE_DEMO] = true;
		}
		
		update_option( self::OPTION_SETTINGS, $saved_settings );
	}
	
	protected function _maybe_set_forced_settings() {
		if ( self::count_ecwid_demo_products() > 0 && self::count_ecwid_demo_products() == self::count_ecwid_products() ) {
			$this->_set_setting( self::SETTING_DELETE_DEMO, true );
		}
	}
	
	protected static function _get_woo_categories( $args ) {
		
		$args = wp_parse_args( $args,
			array(
				'menu_order'   => 'ASC',
				'hide_empty'   => 0,
				'hierarchical' => 1,
				'taxonomy'     => 'product_cat',
				'pad_counts'   => 1,
				'get' 		   => 'all'
			)
		);
		
		if ( isset( $args['parent'] ) && $args['parent'] ) {
			$args = apply_filters( 'woocommerce_product_subcategories_args',  $args );
		}
		
		return get_categories( $args ); 
	}
	
	public static function count_woo_categories()
	{
		$all_categories = self::_get_woo_categories( array( 'count' => true ) );
		
		$count = count($all_categories);
		
		$default = self::_get_woo_categories( array( 'objectIds' => array( get_option ( 'default_product_cat' ) ) ) );
		$children_of_default = self::_get_woo_categories( array( 'parent' => get_option ( 'default_product_cat' ) ) );
		
		if ( count( $default ) > 0 && count( $children_of_default ) == 0 ) {
			$count--;
		}
		
		return $count;
	}
	
	public static function count_woo_products()
	{
		$count = wp_count_posts( 'product' );

		return $count->publish;
	}
	
	public static function count_ecwid_products()
	{
		$api = new Ecwid_Api_V3();

		$max = 100;
		$ecwid_products = $api->get_products( array( 'limit' => $max ) );
		
		if ( $ecwid_products->total <= $max ) {
			$demo = array();
			foreach ( $ecwid_products->items as $item ) {
				if ( $item->isSampleProduct ) {
					$demo[] = $item->id;
				}
			}
			
			EcwidPlatform::set( self::OPTION_DEMO_PRODUCTS, $demo );
		}
		
		return $ecwid_products->total;
	}
	
	public static function count_ecwid_categories()
	{
		$api = new Ecwid_Api_V3();

		$ecwid_categories = $api->get_categories( array( 'limit' => 1 ) );
		return $ecwid_categories->total;
	}
	
	public static function count_ecwid_demo_products()
	{
		$ecwid_products = self::get_ecwid_demo_products();
		
		return count( $ecwid_products );
	}
	
	public static function get_ecwid_demo_products() {
	
		// Actual gathering happens in count_ecwid_products
		// TODO: fix this discrapency
		$demo_products = EcwidPlatform::get( self::OPTION_DEMO_PRODUCTS, array() );
		
		if ( is_array( $demo_products ) ) {
			return $demo_products;
		}
		
		return array();
	}
	
	
	public function gather_categories($parent = 0 )
	{
		$product_categories = self::_get_woo_categories( array( 'parent' => $parent) );
		
		if ( count( $product_categories ) == 0 ) {
			return array();
		}
		
		$result = array();
		foreach ( $product_categories as $category ) {
			
			$children = $this->gather_categories( $category->term_id );
			
			if ( $category->term_id != get_option('default_product_cat') || count($children) > 0 ) {
				$result[] = array(
					'woo_id' => $category->term_id,
					'parent_id' => $parent,
					'has_image' => get_term_meta( $category->term_id, 'thumbnail_id', true )
				);
				
				$result = array_merge(
					$result, 
					$children
				);
			}
		}

		return $result;
	}
	
	public function gather_products()
	{
		$products = get_posts( array( 'post_type' => 'product', 'posts_per_page' => 2500, 'fields' => 'ids' ) );
		
		$return = array();
		foreach ($products as $id ) {
			$p = wc_get_product( $id );
			$return[] = array(
				'woo_id' => $id,
				'has_image' => get_post_thumbnail_id( $id ),
				'gallery_images' => $p->get_gallery_image_ids()
			);
		}
		
		return $return;
	}
}