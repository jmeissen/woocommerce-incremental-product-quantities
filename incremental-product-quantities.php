<?php
/**
 * Plugin Name: WooCommerce Advanced Product Quantities
 * Plugin URI: http://www.wpbackoffice.com/plugins/woocommerce-incremental-product-quantities/
 * Description: Easily require your customers to buy a minimum / maximum / incremental amount of products to continue with their checkout. It is highly recommended to also install 'WooCommerce Thumbnail Input Quantities' to allow users to add your custom quantites from product thumbnails.
 * Version: 2.2.2
 * Author: WP BackOffice
 * Author URI: http://www.wpbackoffice.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'Incremental_Product_Quantities' ) ) :

class Incremental_Product_Quantities {

	public $wc_version;

	/**
	 * @var WooCommerce Supplier instance
	 * @since 2.1
	 */
	protected static $_instance = null;

	/**
	 * Main Incremental Product Quantities Instance
	 *
	 * @return WooCommerce Supplier - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {

		// Activation / Deactivation Hooks
		register_activation_hook( __FILE__, array( $this, 'activation_hook' ) );
		register_activation_hook( __FILE__, array( $this, 'update_rules_with_roles' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation_hook' ) );

		// Include Required Files
		require_once( 'includes/ipq-functions.php' );
		require_once( 'includes/class-ipq-filters.php' );
		require_once( 'includes/class-ipq-actions.php' );
		require_once( 'includes/class-ipq-product-meta-box.php' );
		require_once( 'includes/class-ipq-post-type.php' );
		require_once( 'includes/class-ipq-validations.php' );
		require_once( 'includes/class-ipq-advanced-rules.php' );

		// Add Scripts and styles
		add_action( 'wp_enqueue_scripts', array( $this, 'input_value_validation' ) );
		add_action( 'admin_init', array( $this, 'quantity_styles' ) );

		// Set WC Version Number
		add_action( 'init', array( $this, 'get_wc_version' ) );

		// Control Admin Notices
		add_action( 'admin_notices', array( $this, 'thumbnail_plugin_notice' ) );
		add_action( 'admin_init', array( $this, 'thumbnail_plugin_notice_ignore' ) );

	}

	/**
	 *	Adds default option values
	 */
	public function activation_hook() {

		$options = get_option( 'ipq_options' );

		$defaults = array (
			'ipq_site_rule_active'	=> '',
			'ipq_site_min'			=> '',
			'ipq_site_max' 			=> '',
			'ipq_site_step' 		=> '',
			'ipq_site_rule_active'	=> '',
			'ipq_show_qty_note' 	=> '',
			'ipq_qty_text'			=> 'Minimum Qty: %MIN%',
			'ipq_show_qty_note_pos' => 'below',
			'ipq_qty_class'			=> ''
		);

		// If no options set the defaults
		if ( $options == false ) {
			add_option( 'ipq_options', $defaults, '', false );

		// Otherwise check that all option are set
		} else {
			$needs_update = FALSE;

			// Check and assign each unset value
			foreach ( $defaults as $key => $value ) {
				if ( !isset( $options[$key] ) ) {
					$options[$key] = $value;
					$needs_update = TRUE;
				}
			}

			// If values are missing update the options
			if ( $needs_update === TRUE ) {
				update_option( 'ipq_options', $options );
			}
		}
	}

	/**
	 *	'Checks' all user roles for pre-2.1 rules
	 *
	 *	@status To be depricated in version 2.3
	 */
	public function update_rules_with_roles() {

		// Construct default roles list to apply
		global $wp_roles;
		$roles = $wp_roles->get_names();
		$applied_roles = array();
		foreach ( $roles as $slug => $name ) {
			array_push( $applied_roles, $slug );
		}

		$args = array (
			'posts_per_page'   	=> -1,
			'post_type'        	=> 'quantity-rule',
			'post_status'      	=> 'publish',
		);

		$rules = get_posts( $args );

		// Loop through rules
		foreach ( $rules as $rule ) {
			// If their rule value is false, apply all roles
			$roles = get_post_meta( $rule->ID, '_roles', true );

			if ( $roles == false ) {
				update_post_meta( $rule->ID, '_roles', $applied_roles, false );
			}
		}
	}

	/**
	 *	Remove thumbnail plugin notice meta value
	 */
	public function deactivation_hook() {

		$args = array(
			'meta_key'     => 'wpbo_thumbnail_input_notice',
			'meta_value'   => 'true',
		 );

		$admins = get_users( $args );

		foreach ( $admins as $admin ) {
			delete_user_meta( $admin->ID, 'wpbo_thumbnail_input_notice' );
		}
	}

	/**
	 *	Include JS to round any value that isn't a multiple of the
	 *	step up.
	 */
	public function input_value_validation() {

		global $post;

		// Only display script if we are on a single product or cart page
		if ( is_object( $post ) and $post->post_type == 'product' or is_cart() ) {

			wp_enqueue_script(
				'ipq_validation',
				plugins_url( '/assets/js/ipq_input_value_validation.js', __FILE__ ),
				array( 'jquery' )
			);

			// Only localize parameters for variable products
			if ( ! is_cart() ) {

				$product = wc_get_product( $post );
				if ( $product->get_type() == 'variable' ) {

					// See what rules are being applied
					$rule_result = wpbo_get_applied_rule( $product );

					// If the rule result is inactive, we're done
					if ( $rule_result == 'inactive' || $rule_result == null ) {
						return;

					// Get values for Override, Sitewide and Rule Controlled Products
					} else {
						$values = wpbo_get_value_from_rule( 'all', $product, $rule_result );
					}

					// Check if the product is out of stock
					$stock = $product->get_stock_quantity();

					// Check if the product is under stock management and out of stock
					if ( $stock && strlen( $stock ) != 0 && $stock <= 0 ) {

						if ( $values['min_oos'] != '' ) {
							$values['min_value'] = $values['min_oos'];
						}

						if ( $values['max_oos'] != '' ) {
							$values['max_value'] = $values['max_oos'];
						}

					}

					// Output admin-ajax.php URL with sma eprotocol as current page
					$params = array (
						'min' => $values['min_value'],
						'max' => $values['max_value'],
						'step' => $values['step']
					);

					wp_localize_script( 'ipq_validation', 'ipq_validation', $params );
				}
			}
		}
	}

	/**
	 *	Include Styles
	 */
	public function quantity_styles() {

		if ( is_admin() ) {

			wp_enqueue_style(
				'ipq_quantity_styles',
				plugins_url( '/assets/css/styles.css', __FILE__ )
			);

			wp_enqueue_script(
				'ipq_admin_script',
				plugins_url( '/assets/js/ipq_admin_script.js', __FILE__ ),
				array( 'jquery' )
			);
		}
	}

	/**
	 *	Set what version of WooCommerce the user has installed
	 */
	public function get_wc_version() {

		if ( ! function_exists( 'get_plugins' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$plugin_folder = get_plugins( '/' . 'woocommerce' );
		$plugin_file = 'woocommerce.php';

		if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
			$this->wc_version = $plugin_folder[$plugin_file]['Version'];
		} else {
			$this->wc_version = NULL;
		}
	}

	/**
	 * 	General Admin Notice to Encourage users to download thumbnail input as well
	 */
	public function thumbnail_plugin_notice() {

		global $current_user;
		$user_id = $current_user->ID;

		// Check if Thumbnail Plugin is activated
		if ( !in_array( 'woocommerce-thumbnail-input-quantities/woocommerce-thumbnail-input-quantity.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

			// Check if User has Dismissed this message already
			if ( ! get_user_meta( $user_id, 'wpbo_thumbnail_input_notice' ) ) {

				echo '<div class="updated">
			       <p><strong>Notice:</strong> It is highly recommended you install and activate the <a href="http://wordpress.org/plugins/woocommerce-thumbnail-input-quantities/" target="_blank">WooCommerce Thumbnail Input Quantites</a> plugin to display input boxes on products thumbnails. <a href="';

			       // Echo the current url
			       echo site_url() . $_SERVER['REQUEST_URI'];

			       // Echo notice variable as nth get variable with &
			       if ( strpos( $_SERVER['REQUEST_URI'] , '?' ) !== false ) {
				       echo '&wpbo_thumbnail_plugin_dismiss=0';
				   // Echo notice variable as first get variable with ?
			       } else {
				       echo '?wpbo_thumbnail_plugin_dismiss=0';
			       }

			    echo '">Dismiss Notice</a></p></div>';
			}
		}
	}

	/**
	 *	Make Admin Notice Dismissable
	 */
	public function thumbnail_plugin_notice_ignore() {
		global $current_user;
		$user_id = $current_user->ID;

		if ( isset($_GET['wpbo_thumbnail_plugin_dismiss']) && '0' == $_GET['wpbo_thumbnail_plugin_dismiss'] ) {
			add_user_meta($user_id, 'wpbo_thumbnail_input_notice', 'true', true);
		}
	}
}

endif;

/**
 * Returns the main instance of WCS to prevent the need to use globals.
 *
 * @since  1.0
 * @return WCS_Supplier
 */
function IPQ() {
	return Incremental_Product_Quantities::instance();
}

// Global for backwards compatibility.
$GLOBALS['ipq'] = IPQ();
