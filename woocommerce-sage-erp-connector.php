<?php
/**
 * Plugin Name: WooCommerce Sage ERP Connector
 * Plugin URI: https://github.com/skyverge/woocommerce-sage-erp-connector
 * Description: Export customer and order information to Sage ERP 100 via the Sage eBusiness Web Services API
 * Author: SkyVerge
 * Author URI: http://www.skyverge.com
 * Version: 1.3
 * Text Domain: woocommerce-sage-erp-connector
 * Domain Path: /languages/
 *
 * Copyright: (c) 2012-2015 SkyVerge, Inc. (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Sage-ERP-Connector
 * @author    SkyVerge
 * @category  Integration
 * @copyright Copyright (c) 2013-2015, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Load Dependency Checker
if ( ! class_exists( 'WC_Sage_ERP_Connector_Dependencies' ) ) {
	require( 'classes/class-wc-sage-erp-connector-dependencies.php' );
}

// Check if WooCommerce is active
if ( ! WC_Sage_ERP_Connector_Dependencies::is_woocommerce_active() ) {
	return;
}

/**
 * The WC_Sage_ERP_Connector global object
 * @name $wc_sage_erp_connector
 * @global WC_Sage_ERP_Connector $GLOBALS['wc_sage_erp_connector']
 */
$GLOBALS['wc_sage_erp_connector'] = new WC_Sage_ERP_Connector();


/**
 * # WooCommerce Sage ERP Connector Main Plugin Class
 *
 * ## Plugin Overview
 *
 * This plugin exports customers and orders to Sage ERP 100 (and 100 premium) via the Sage eBusiness Web Services SOAP API.
 *
 * ## Terminology
 *
 * + `CustomerNo` - The Sage ERP customer identifier, which consists of two parts: 1) a division number and 2) the customer number, separated by a dash. e.g. 04-SKYVERGE
 * + `DivisionNo` - The class of trade identifier for a customer. Companies use this to separate customers into different sections (e.g. 01 for Wholesalers, 02 for Consumers, etc)
 * + `PriceLevel` - The pricing identifier for a customer which determines which prices are used when creating sales orders
 *
 * ## Admin Considerations
 *
 * This plugin exists entirely in the admin and adds a number of modifications to the admin UI:
 * + Bulk filter for exporter/un-exported orders
 * + Bulk actions for exporting orders, and marking them as exported/un-exported
 * + An 'Sage ERP' status column that indicates whether the order has been exported or not
 * + An 'Export to Sage ERP' order action button to export the order
 *
 * ## Database
 *
 * ### Global Settings
 *
 * + `wc_sage_erp_connector_options` - serialized options
 *
 * ### Options table
 *
 * + `wc_sage_erp_connector_version` - the current plugin version, set on install/upgrade
 *
 * ### Order meta
 *
 * + `_order_number` - the Sage ERP sales order number for the order, only available if the order has been exported
 * + `_wc_sage_erp_exported` - true if the order has been exported to Sage ERP, false otherwise
 * + `_wc_sage_erp_division_no` - the Sage ERP division number that the order was exported under
 * + `_wc_sage_erp_customer_no` - the Sage ERP customer number that the order was exported under
 *
 * ### User meta
 *
 * + `_wc_sage_erp_exported` - true if the customer has been created/exported to Sage ERP, false otherwise
 * + `_wc_sage_erp_division_no` - the Sage ERP division number for the customer
 * + `_wc_sage_erp_customer_no` - the Sage ERP customer number for the customer
 *
 */
class WC_Sage_ERP_Connector {


	/** plugin version number */
	const VERSION = '1.3';

	/** plugin text domain */
	const TEXT_DOMAIN = 'woocommerce-sage-erp-connector';

	/** @var object WP_Admin_Message_Handler instance */
	public $messages;

	/** @var string the plugin path */
	private $plugin_path;

	/** @var string the plugin url */
	private $plugin_url;

	/** @var \WC_Logger instance */
	private $logger;


	/**
	 * Initializes the plugin
	 *
	 * @since 1.0
	 */
	public function __construct() {

		// load integration
		add_action( 'plugins_loaded', array( $this, 'includes' ) );

		// set new orders as not exported
		add_action( 'wp_insert_post',   array( $this, 'set_not_exported' ), 10, 2 );

		// validate fields on checkout & my account pages so they don't exceed Sage ERP field length limits
		add_action( 'woocommerce_checkout_process',                       array( $this, 'validate_customer_fields' ) );
		add_filter( 'woocommerce_process_myaccount_field_billing_phone',  array( $this, 'validate_customer_fields' ) );
		add_filter( 'woocommerce_process_myaccount_field_shipping_phone', array( $this, 'validate_customer_fields' ) );

		// admin
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {

			// show CustomerNo and ARDivisionNo fields on edit user pages
			add_action( 'show_user_profile', array( $this, 'render_user_meta_fields' ) );
			add_action( 'edit_user_profile', array( $this, 'render_user_meta_fields' ) );

			// save CustomerNo and ARDivisionNo fields on edit user pages
			add_action( 'personal_options_update',  array( $this, 'save_user_meta_fields' ) );
			add_action( 'edit_user_profile_update', array( $this, 'save_user_meta_fields' ) );

			// check dependencies
			add_action( 'admin_notices', 'WC_Sage_ERP_Connector_Dependencies::check_dependencies' );

			// add a 'Configure' link to the plugin action links
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_configure_link' ) );

			// run every time
			$this->install();
		}

		// load translation
		add_action( 'init', array( $this, 'load_translation' ) );
	}


	/**
	 * Include required files
	 *
	 * @since 1.0
	 */
	public function includes() {

		// load integration
		require_once( 'classes/class-wc-sage-erp-connector-integration.php' );

		add_filter( 'woocommerce_integrations', array( $this, 'load_integration' ) );
	}


	/**
	 * Add integration to the list of WC Integrations to load
	 *
	 * @since 1.0
	 */
	public function load_integration( $integrations ) {

		$integrations[] = 'WC_Sage_ERP_Connector_Integration';

		return $integrations;
	}


	/**
	 * Set the _erp_exported meta to false if this is a new shop order record.  This is done on order creation mainly so
	 * that we can distinguish between orders that may have been placed pre-ERP integration, vs. ones that are placed after.
	 * Pre-ERP orders are ignored.
	 *
	 * @param int $post_id post ID
	 * @param object $post post object
	 */
	public function set_not_exported( $post_id, $post ) {

		if ( $post->post_type == 'shop_order' ) {
			add_post_meta( $post_id, '_wc_sage_erp_exported', 0, true );  // force unique, because oddly this can be invoked when changing the status of an existing order
		}
	}


	/**
	 * Validate the customer fields on checkout & my account pages to ensure they don't exceed Sage ERP field length limits
	 *
	 * @param string $value set only when called as filter
	 * @return string $value if set
	 */
	public function validate_customer_fields( $value ) {

		// Get posted checkout_fields and do validation
		foreach ( array( 'billing','shipping' ) as $prefix ) {
			$name = '';
			$company = false;
			if ( isset( $_POST[$prefix . '_first_name'] ) && $_POST[$prefix . '_first_name'] ) {
				$name .= wc_clean( $_POST[$prefix . '_first_name'] );
			}
			if ( isset( $_POST[$prefix . '_last_name'] ) && $_POST[$prefix . '_last_name'] ) {
				$name .= " " . wc_clean( $_POST['billing_last_name'] );
			}
			if ( isset( $_POST[$prefix . '_company'] ) && $_POST[$prefix . '_company'] ) {
				$company = true;
				$name .= " - " . wc_clean( $_POST[$prefix . '_company'] );
			}

			// Sage doesn't allow name fields longer than 30 characters
			if ( strlen( $name ) > 30 ) {
				if ( $company ) {
					wc_add_notice( sprintf( '<strong>%s first name, last name and company name</strong>', ucwords( $prefix ) ) . __( ' must not be longer than 26 characters, please shorten some or all of them.', 'wc_sage_erp_mas_connector' ), 'error' );
				} else {
					wc_add_notice( sprintf( '<strong>%s first name and last name</strong>', ucwords( $prefix ) ) . __( ' must not be longer than 29 characters, please shorten them.', 'wc_sage_erp_mas_connector' ), 'error' );
				}
			}

			// address 1/address 2 must be no longer than 30 characters
			if ( isset ( $_POST[$prefix . '_address_1'] ) && strlen( $_POST[$prefix . '_address_1'] ) > 30 ) {
				wc_add_notice( sprintf( '<strong>%s address 1</strong>', ucwords( $prefix ) ) . __( ' must not be longer than 30 characters.', 'wc_sage_erp_mas_connector' ), 'error' );
			}
			if ( isset ( $_POST[$prefix . '_address_2'] ) && strlen( $_POST[$prefix . '_address_2'] ) > 30 ) {
				wc_add_notice( sprintf( '<strong>%s address 2</strong>', ucwords( $prefix ) ) . __( ' must not be longer than 30 characters.', 'wc_sage_erp_mas_connector' ), 'error');
			}

			// city must be no longer than 20 characters
			if ( isset ( $_POST[$prefix . '_city'] ) && strlen( $_POST[$prefix . '_city'] ) > 20 ) {
				wc_add_notice( sprintf( '<strong>%s city</strong>', ucwords( $prefix ) ) . __( ' must not be longer than 20 characters.', 'wc_sage_erp_mas_connector' ), 'error' );
			}

			// postcode must be no longer than 10 characters
			if ( isset ( $_POST[$prefix . '_postcode'] ) && strlen( $_POST[$prefix . '_postcode'] ) > 10 ) {
				wc_add_notice( sprintf( '<strong>%s Postcode</strong>', ucwords( $prefix ) ) . __( ' must not be longer than 10 characters.', 'wc_sage_erp_mas_connector' ), 'error' );
			}
		}

		return $value;
	}


	/**
	 * Handle localization, WPML compatible
	 *
	 * @since 1.0
	 */
	public function load_translation() {

		// localization in the init action for WPML support
		load_plugin_textdomain( 'woocommerce-sage-erp-connector', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}


	/** Admin methods ******************************************************/


	/**
	 * Display fields for the CustomerNo and ARDivisionNo user meta on the view/edit user page
	 *
	 * @since 1.0.1
	 * @param WP_User $user user object for the current edit page
	 */
	public function render_user_meta_fields( $user ) {

		// bail if the current user is not allowed to manage woocommerce
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		?>
			<h3><?php _e( 'Sage ERP Customer Details', self::TEXT_DOMAIN ) ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="_wc_sage_erp_customer_no"><?php _e( 'Customer Number', self::TEXT_DOMAIN ); ?></label></th>
					<td>
						<input type="text" name="_wc_sage_erp_customer_no" id="_wc_sage_erp_customer_no" value="<?php echo esc_attr( $user->_wc_sage_erp_customer_no ); ?>" class="regular-text" /><br/>
						<span class="description"><?php _e( 'The Sage ERP Customer Number for the user. Only edit this if necessary.', self::TEXT_DOMAIN ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="_wc_sage_erp_division_no"><?php _e( 'AR Division Number', self::TEXT_DOMAIN ); ?></label></th>
					<td>
						<input type="text" name="_wc_sage_erp_division_no" id="_wc_sage_erp_division_no" value="<?php echo esc_attr( $user->_wc_sage_erp_division_no ); ?>" class="regular-text" /><br/>
						<span class="description"><?php _e( 'The Sage ERP AR Division Number for the user. Only edit this if necessary.', self::TEXT_DOMAIN ); ?></span>
					</td>
				</tr>
			</table>
		<?php
	}


	/**
	 * Save fields for the CustomerNo and ARDivisionNo user meta on the view/edit user page
	 *
	 * @since 1.0.1
	 * @param int $user_id identifies the user to save the settings for
	 */
	public function save_user_meta_fields( $user_id ) {

		// bail if the current user is not allowed to manage woocommerce
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// CustomerNo
		if ( ! empty( $_POST['_wc_sage_erp_customer_no'] ) ) {
			update_user_meta( $user_id, '_wc_sage_erp_customer_no', trim( $_POST['_wc_sage_erp_customer_no'] ) );
		} else {
			delete_user_meta( $user_id, '_wc_sage_erp_customer_no' );
		}

		// ARDivisionNo
		if ( ! empty( $_POST['_wc_sage_erp_division_no'] ) ) {
			update_user_meta( $user_id, '_wc_sage_erp_division_no', trim( $_POST['_wc_sage_erp_division_no'] ) );
		} else {
			delete_user_meta( $user_id, '_wc_sage_erp_division_no' );
		}
	}


	/**
	 * Return the plugin action links.  This will only be called if the plugin
	 * is active.
	 *
	 * @since 1.0
	 * @param array $actions associative array of action names to anchor tags
	 * @return array associative array of plugin action links
	 */
	public function add_plugin_configure_link( $actions ) {
		// add the link to the front of the actions list
		return ( array_merge( array( 'configure' => sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=woocommerce&tab=integration&section=sage_erp_connector' ), __( 'Configure', self::TEXT_DOMAIN ) ) ),
			$actions )
		);
	}


	/** Helper methods ******************************************************/


	/**
	 * Gets the absolute plugin path without a trailing slash, e.g.
	 * /path/to/wp-content/plugins/plugin-directory
	 *
	 * @since 1.0
	 * @return string plugin path
	 */
	public function get_plugin_path() {

		if ( $this->plugin_path ) {
			return $this->plugin_path;
		}

		return $this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );
	}


	/**
	 * Gets the plugin url without a trailing slash
	 *
	 * @since 1.0
	 * @return string the plugin url
	 */
	public function get_plugin_url() {

		if ( $this->plugin_url ) {
			return $this->plugin_url;
		}

		return $this->plugin_url = untrailingslashit( plugins_url( '/', __FILE__ ) );
	}


	/**
	 * Log errors / messages to WooCommerce error log (/wp-content/woocommerce/logs/)
	 *
	 *
	 * @since 1.0
	 * @param string $message
	 */
	public function log( $message ) {

		if ( ! is_object( $this->logger ) ) {
			$this->logger = new WC_Logger();
		}

		$this->logger->add( 'sage-erp-connector', $message );
	}


	/** Lifecycle methods ******************************************************/


	/**
	 * Run every time.  Used since the activation hook is not executed when updating a plugin
	 *
	 * @since 0.1
	 */
	private function install() {

		// get current version to check for upgrade
		$installed_version = get_option( 'wc_sage_erp_connector_version' );

		// install
		if ( ! $installed_version ) {

			// install default settings, terms, etc
		}

		// upgrade if installed version lower than plugin version
		if ( -1 === version_compare( $installed_version, self::VERSION ) )
			$this->upgrade( $installed_version );
	}


	/**
	 * Perform any version-related changes
	 *
	 * @since 1.0
	 * @param int $installed_version the currently installed version of the plugin
	 */
	private function upgrade( $installed_version ) {

		// upgrade to 1.0 code
		if ( version_compare( $installed_version, '1.0' ) < 0 ) {
			global $wpdb;

			// update order meta keys
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_key' => '_wc_sage_erp_customer_no' ),
				array( 'meta_key' => '_erp_customer_id' )
			);

			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_key' => '_wc_sage_erp_exported' ),
				array( 'meta_key' => '_erp_exported' )
			);

			// update user meta keys
			$wpdb->update(
				$wpdb->usermeta,
				array( 'meta_key' => '_wc_sage_erp_customer_no' ),
				array( 'meta_key' => '_erp_customer_id' )
			);

			$wpdb->update(
				$wpdb->usermeta,
				array( 'meta_key' => '_wc_sage_erp_exported' ),
				array( 'meta_key' => '_erp_exported' )
			);
		}

		// update the installed version option
		update_option( 'wc_sage_erp_connector_version', self::VERSION );
	}


}
