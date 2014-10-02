<?php
/**
 * WooCommerce Sage ERP Connector
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@skyverge.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Sage ERP Connector to newer
 * versions in the future. If you wish to customize WooCommerce Sage ERP Connector for your
 * needs please refer to http://www.skyverge.com/contact/ for more information.
 *
 * @package     WC-Sage-ERP-Connector/Integration
 * @author      SkyVerge
 * @copyright   Copyright (c) 2013, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Integration Class
 *
 * Sets up the admin UI to handle order exports and renders/saves the integration settings
 *
 * @since 1.0
 * @extends \WC_Integration
 */
class WC_Sage_ERP_Connector_Integration extends WC_Integration {


	/** @var string company code to import orders into */
	public $company_code;

	/** @var string division no to import customers/orders under */
	public $division_no;

	/** @var string salesperson no to import orders under */
	public $salesperson_no;

	/** @var string price level to import customers/orders under */
	public $price_level;

	/** @var string test mode that enables un-exporting orders */
	public $test_mode;

	/** @var string restrict export of orders to certain order statuses */
	public $restrict_export;

	/** @var string the endpoint URL for the eBusiness Web Services API */
	public $api_endpoint;

	/** @var string the API username */
	public $api_username;

	/** @var string the API password */
	public $api_password;


	/**
	 * Load the integration and add required hooks
	 *
	 * @since 1.0
	 * @return \WC_Sage_ERP_Connector_Integration
	 */
	public function __construct() {
		global $wc_sage_erp_connector;

		// set integration info
		$this->id                 = 'sage_erp_connector';
		$this->method_title       = __( 'Sage ERP Connector', WC_Sage_ERP_Connector::TEXT_DOMAIN );
		$this->method_description = __( 'Easily export customers and orders to Sage ERP', WC_Sage_ERP_Connector::TEXT_DOMAIN );

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// define class members
		foreach ( $this->settings as $setting_key => $setting ) {
			$this->$setting_key = $setting;
		}

		// load custom admin styles / scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'load_styles_scripts' ) );

		// activate export functionality if integration is properly configured
		if ( $this->is_active() ) {

			// load admin message handler
			require_once( $wc_sage_erp_connector->get_plugin_path() . '/includes/class-wp-admin-message-handler.php' );
			$wc_sage_erp_connector->messages = new WP_Admin_Message_Handler( __FILE__ );

			// load exporter
			require_once( $wc_sage_erp_connector->get_plugin_path() . '/classes/class-wc-sage-erp-connector-exporter.php' );

			/* View / Edit Order page hooks */

			// add 'Sage ERP Status' orders page column header
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_status_column_header' ), 20 );

			// add 'Sage ERP Status' orders page column content
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_order_status_column_content' ) );

			// add 'Export to Sage ERP' action on orders page
			add_action( 'woocommerce_admin_order_actions_end', array( $this, 'add_order_action' ) );

			// process AJAX action for 'Export to Sage ERP' actions on orders page
			add_action( 'wp_ajax_wc_sage_erp_connector_export_order', array( $this, 'process_ajax_order_action' ) );

			// add 'Export to Sage ERP' order meta box order action
			add_action( 'woocommerce_order_actions', array( $this, 'add_order_meta_box_actions' ) );

			// process 'Export to Sage ERP' order meta box order action
			add_action( 'woocommerce_order_action_wc_sage_erp_connector_export_order', array( $this, 'process_order_meta_box_actions' ) );

			// add bulk order filter for exported / non-exported orders
			add_action( 'restrict_manage_posts', array( $this, 'filter_orders_by_export_status') , 20 );
			add_filter( 'request',               array( $this, 'filter_orders_by_export_status_query' ) );

			// add bulk action to export multiple orders to Sage ERP and mark them as exported / not-exported
			add_action( 'admin_footer-edit.php', array( $this, 'add_order_bulk_actions' ) );
			add_action( 'load-edit.php',         array( $this, 'process_order_bulk_actions' ) );

			// support custom order numbers in backend
			add_filter( 'request', array( $this, 'shop_order_orderby' ), 20 );
			add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'shop_order_search_fields' ) );

			// render admin notices for messages/errors during export
			add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		}

		/* Hooks for supporting custom order numbers */

		// display Sage ERP Sales Order numbers instead of the WP Post ID
		add_filter( 'woocommerce_order_number', array( $this, 'get_order_number' ), 10, 2 );

		// support order tracking lookup by custom order number
		add_filter( 'woocommerce_shortcode_order_tracking_order_id', array( $this, 'find_order_by_order_number' ) );

		// make settings available globally
		$wc_sage_erp_connector->integration = $this;

		// save settings
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options') );
	}


	/**
	 * Define the integration setting fields
	 *
	 * @since 1.0
	 */
	public function init_form_fields() {

		$this->form_fields = array(

			'company_code' => array(
				'title'    => __( 'Company Code', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip' => __( 'Orders will be imported into this company.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'     => 'text',
				'default'  => ''
			),

			'division_no' => array(
				'title'    => __( 'Division Number', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip' => __( 'Customers will be created in this division. This can be modified on a per-customer basis with custom code. Submit a support ticket to have this modification done for your company.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'     => 'text',
				'default'  => ''
			),

			'salesperson_no' => array(
				'title'    => __( 'Salesperson', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip' => __( 'Customers will be created under this salesperson.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'     => 'text',
				'default'  => ''
			),

			'price_level' => array(
				'title'    => __( 'Price Level', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip' => __( 'Orders will be imported at this price level.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'     => 'text',
				'default'  => ''
			),

			'test_mode' => array(
				'title'       => __( 'Test Mode', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'description' => __( 'Enables testing features.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'        => 'checkbox',
				'default'     => ''
			),

			// TODO: change this to a checkbox
			/*'restrict_export' => array(
				'title'       => __( 'Restrict Order Export', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip'    => __( 'Restrict order export to orders with this status', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'        => 'select',
				'default'     => 'none',
				'options'     => array(
					'none'       => __( 'None', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
					'pending'    => __( 'Pending', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
					'processing' => __( 'Processing', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
					'completed'  => __( 'Completed', WC_Sage_ERP_Connector::TEXT_DOMAIN )
				)
			),*/

			'api_endpoint' => array(
				'title'    => __( 'API Endpoint', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip' => __( 'Provide the Sage ERP API endpoint URL.  This is where your WSDL file can be found.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'     => 'text',
				'default'  => ''
			),

			'api_username' => array(
				'title'    => __( 'Username', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip' => __( 'Provide your Sage ERP API username.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'     => 'text',
				'default'  => ''
			),

			'api_password' => array(
				'title'    => __( 'Password', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'desc_tip' => __( 'Provide your Sage ERP API password.', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
				'type'     => 'password',
				'default'  => ''
			),
		);
	}


	/** General admin methods ******************************************************/


	/**
	 * Load admin styles & scripts only on needed pages
	 *
	 * @since 1.0
	 * @param string $hook_suffix the current page suffix
	 */
	public function load_styles_scripts( $hook_suffix ) {
		global $wc_sage_erp_connector;

		// only load admin css on order page
		if ( 'edit.php' == $hook_suffix  ) {
			wp_enqueue_style( 'wc_sage_erp_connector_', $wc_sage_erp_connector->get_plugin_url() . '/assets/css/wc-sage-erp-connector-admin.min.css', WC_Sage_ERP_Connector::VERSION );
		}
	}


	/** View / Edit Order admin methods ******************************************************/


	/**
	 * Adds 'Sage ERP Status' column header to 'Orders' page immediately after 'Order Status' column
	 *
	 * @since 1.0
	 * @param array $columns
	 * @return array $new_columns
	 */
	public function add_order_status_column_header( $columns ) {

		$new_columns = array();

		foreach ( $columns as $column_name => $column_info ) {

			$new_columns[ $column_name ] = $column_info;

			if ( 'order_status' == $column_name ) {
				$new_columns['sage_erp_export_status'] = __( 'Export Status', WC_Sage_ERP_Connector::TEXT_DOMAIN );
			}
		}

		return $new_columns;
	}


	/**
	 * Adds 'Export Status' column content to 'Orders' page immediately after 'Order Status' column
	 *
	 * 'Not Exported' - if '_wc_sage_erp_exported' order meta doesn't exist or is equal to 0
	 * 'Exported' - if '_wc_sage_erp_exported' order meta exists and is equal to 1
	 *
	 * @since 1.0
	 * @param array $column name of column being displayed
	 */
	public function add_order_status_column_content( $column ) {
		global $post;

		if ( 'sage_erp_export_status' == $column ) {

			$order = wc_get_order( $post->ID );

			$is_exported = ( isset( $order->wc_sage_erp_exported ) && (bool) $order->wc_sage_erp_exported );

			printf( '<mark class="%1$s tips" data-tip="%2$s">%2$s</mark>', ( true == $is_exported ) ? 'sage_erp_exported' : 'sage_erp_not_exported', ( true == $is_exported ) ? __( 'Exported', WC_Sage_ERP_Connector::TEXT_DOMAIN ) : __( 'Not Exported', WC_Sage_ERP_Connector::TEXT_DOMAIN ) );
		}
	}


	/**
	 * Adds 'Export to Sage ERP' order action to 'Order Actions' column
	 *
	 * Processed via AJAX
	 *
	 * @since 1.0
	 * @param object $order WC_Order object
	 */
	public function add_order_action( $order ) {
		global $wc_sage_erp_connector;

		$ico_url = $wc_sage_erp_connector->get_plugin_url() . '/assets/images/sage-ico.png';

		$name = __( 'Export to Sage ERP', WC_Sage_ERP_Connector::TEXT_DOMAIN );

		if ( ! isset( $order->wc_sage_erp_exported ) || ! $order->wc_sage_erp_exported ) {
			printf( '<a class="button tips" href="%s" data-tip="%s"><img style="width: 24px;" src="%s" alt="%s" class="wc_sage_erp_export_icon" /></a>', wp_nonce_url( admin_url( 'admin-ajax.php?action=wc_sage_erp_connector_export_order&order_id=' . $order->id ), 'wc_sage_erp_connector_export_order' ), $name, $ico_url, $name );
		}
	}


	/**
	 * Processes 'Export to Sage ERP' order action to 'Order Actions' column
	 *
	 * @since 1.0
	 */
	public function process_ajax_order_action() {

		// permissions checks
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', WC_Sage_ERP_Connector::TEXT_DOMAIN ) );
		}

		// security check
		if ( ! check_admin_referer( 'wc_sage_erp_connector_export_order' ) ) {
			wp_die( __('You have taken too long. Please go back and retry.', WC_Sage_ERP_Connector::TEXT_DOMAIN ) );
		}

		// get order ID
		$order_id = ( isset( $_GET['order_id'] ) && is_numeric( $_GET['order_id'] ) ) ? (int) $_GET['order_id'] : '';

		if ( ! $order_id ) {
			die;
		}

		// export the order
		$sage_order = new WC_Sage_ERP_Connector_Exporter( $order_id );

		$sage_order->export();

		wp_redirect( wp_get_referer() );
	}


	/**
	 * Add 'Export to Sage ERP' link to Order Actions dropdown
	 *
	 * @since 1.0
	 * @param array $actions order actions array to display
	 * @return array
	 */
	public function add_order_meta_box_actions( $actions ) {

		// add export to Sage ERP action
		$actions['wc_sage_erp_connector_export_order'] = __( 'Export to Sage ERP', WC_Sage_ERP_Connector::TEXT_DOMAIN );

		return $actions;
	}


	/**
	 * Process the 'Export to Sage ERP' link in Order Actions dropdown
	 *
	 * @since 1.0
	 * @param object $order \WC_Order object
	 */
	public function process_order_meta_box_actions( $order ) {

		$sage_order = new WC_Sage_ERP_Connector_Exporter( $order->id );

		$sage_order->export();
	}


	/**
	 * Add bulk filter for Exported / Un-Exported orders
	 *
	 * @since 1.0
	 */
	public function filter_orders_by_export_status() {
		global $typenow;

		if ( 'shop_order' == $typenow ) :

			$count_new   = $this->get_unexported_order_count();
			$count_total = wp_count_posts( 'shop_order' );
			$count_total = $count_total->publish;

			$count_exported = $count_total - $count_new;

			$terms = array(
				0 => (object) array( 'count' => $count_new, 'term' => __( 'Not Exported', WC_Sage_ERP_Connector::TEXT_DOMAIN ) ),
				1 => (object) array( 'count' => $count_exported, 'term' => __( 'Exported', WC_Sage_ERP_Connector::TEXT_DOMAIN ) )
			);

			?>
			<select name="_shop_order_export_status" id="dropdown_shop_order_export_status">
				<option value=""><?php _e( 'Show all orders', WC_Sage_ERP_Connector::TEXT_DOMAIN ); ?></option>
				<?php foreach ( $terms as $value => $term ) : ?>
					<option value="<?php echo $value; ?>" <?php echo esc_attr( isset( $_GET['_shop_order_export_status'] ) ? selected( $value, $_GET['_shop_order_export_status'], false ) : '' ); ?>>
						<?php printf( '%s (%s)', $term->term, $term->count ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php

		endif;
	}


	/**
	 * Process bulk filter action for Export / Un-Exported orders
	 *
	 * @since 1.0
	 * @param array $vars query vars without filtering
	 * @return array $vars query vars with (maybe) filtering
	 */
	public static function filter_orders_by_export_status_query( $vars ) {
		global $typenow;

		if ( 'shop_order' == $typenow && isset( $_GET['_shop_order_export_status'] ) && is_numeric( $_GET['_shop_order_export_status'] ) ) {

			$vars['meta_key']   = '_wc_sage_erp_exported';
			$vars['meta_value'] = (int) $_GET['_shop_order_export_status'];
		}

		return $vars;
	}


	/**
	 * Add "Export to Sage ERP" custom bulk action to the 'Orders' page bulk action drop-down
	 *
	 * @since 1.0
	 */
	public function add_order_bulk_actions() {
		global $post_type, $post_status;

		if ( $post_type == 'shop_order' && $post_status != 'trash' ) :
			?>
			<script type="text/javascript">
				jQuery(document).ready(function ($) {
					var $exported = $('<option>').val('mark_exported').text('<?php _e( 'Mark exported', WC_Sage_ERP_Connector::TEXT_DOMAIN )?>'),
						$notExported = $('<option>').val('mark_not_exported').text('<?php _e( 'Mark not exported', WC_Sage_ERP_Connector::TEXT_DOMAIN )?>'),
						$exportToSageERP = $('<option>').val('export_to_sage_erp').text('<?php _e( 'Export to Sage ERP', WC_Sage_ERP_Connector::TEXT_DOMAIN )?>');

					$('select[name^="action"]').append( $exported, $notExported, $exportToSageERP );
				});
			</script>
			<?php
		endif;
	}


	/**
	 * Processes the "Export to Sage ERP" custom bulk action on the 'Orders' page bulk action drop-down
	 *
	 * @since 1.0
	 */
	public function process_order_bulk_actions() {
		global $typenow;

		if ( 'shop_order' == $typenow ) {

			// get the action
			$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
			$action        = $wp_list_table->current_action();

			// return if not processing our actions
			if ( ! in_array( $action, array( 'export_to_sage_erp', 'mark_exported', 'mark_not_exported' ) ) ) {
				return;
			}

			// security check
			check_admin_referer( 'bulk-posts' );

			// make sure order IDs are submitted
			if ( isset( $_REQUEST['post'] ) )
				$order_ids = array_map( 'absint', $_REQUEST['post'] );

			// return if there are no orders to export
			if ( empty( $order_ids ) )
				return;

			// give ourselves an unlimited timeout if possible
			@set_time_limit( 0 );

			if ( 'export_to_sage_erp' == $action ) {

				// setup export class
				$orders = new WC_Sage_ERP_Connector_Exporter( $order_ids );

				// export the orders
				$orders->export();

			} else {

				// mark each order as exported / not exported
				foreach( $order_ids as $order_id ) {
					update_post_meta( $order_id, '_wc_sage_erp_exported', ( 'mark_exported' == $action ) ? 1 : 0 );
				}
			}

			// TODO: handle bulk un-export action
		}
	}

	/**
	 * Get the number of orders that are not exported
	 * Note this only includes orders where the 'is_exported' meta is set to 0
	 * Orders placed prior to the installation / activation of the plugin will be counted as exported
	 *
	 * @since 1.0
	 * @return int number of unexported orders
	 */
	private function get_unexported_order_count() {

		$query_args = array(
			'fields'      => 'ids',
			'post_type'   => 'shop_order',
			'post_status' => 'any',
			'meta_query'  => array(
				array(
					'key'   => '_wc_sage_erp_exported',
					'value' => 0
				)
			)
		);

		$query = new WP_Query( $query_args );

		return count( $query->posts );
	}


	/**
	 * Display an admin notice on the Orders page after exporting to Sage ERP
	 * This gives an overview: number of orders exported successfully/unsuccessfully, along with more detailed error messages per order when needed
	 *
	 * @since 1.0
	 */
	public function render_admin_notices() {
		global $post_type, $pagenow, $wc_sage_erp_connector;

		if ( ( 'edit.php' == $pagenow || 'post.php' == $pagenow ) && $post_type == 'shop_order' ) {
			$wc_sage_erp_connector->messages->show_messages();
		}
	}


	/** Custom order number methods ******************************************************/


	/**
	 * Display the custom order number (from the Sage ERP Sales Order number) instead of the order's post ID
	 *
	 * @since 1.0
	 * @param string $order_number current order number
	 * @param object $order the \WC_Order object
	 * @return string the formatted order number
	 */
	public function get_order_number( $order_number, $order ) {

		if ( isset( $order->wc_sage_erp_exported ) ) {

			if ( isset( $order->order_number ) ) {
				return '#' . $order->order_number;
			} else {
				return 'ID ' . $order->id;
			}
		}

		return $order_number;
	}


	/**
	 * Enable ordering orders by custom order number
	 *
	 * @since 1.0
	 * @param array $vars query vars
	 * @return array
	 */
	public function shop_order_orderby( $vars ) {
		global $typenow;

		if ( 'shop_order' != $typenow ) {
			return $vars;
		}

		// add custom order number to orderby vars
		if ( isset( $vars['orderby'] ) && 'ID' == $vars['orderby'] ) {

			$vars = array_merge( $vars,
				array(
					'meta_key' => '_order_number',
					'orderby'  => 'meta_value_num'
				)
			);
		}

		return $vars;
	}


	/**
	 * Enable searches via custom order number on the shop order page
	 *
	 * @since 1.0
	 * @param array $search_fields array of post meta fields to search by
	 * @return array of post meta fields to search by
	 */
	public function shop_order_search_fields( $search_fields ) {

		array_push( $search_fields, '_order_number' );

		return $search_fields;
	}


	/**
	 * Search for an order with order_number $order_number
	 *
	 * @since 1.0
	 * @param string $order_number order number to search for
	 * @return int post_id for the order identified by $order_number, or 0
	 */
	public function find_order_by_order_number( $order_number ) {

		// search for the order by custom order number
		$query_args = array(
			'numberposts' => 1,
			'meta_key'    => '_order_number',
			'meta_value'  => $order_number,
			'post_type'   => 'shop_order',
			'post_status' => 'any',
			'fields'      => 'ids'
		);

		list( $order_id ) = get_posts( $query_args );

		// order was found
		if ( $order_id !== null ) {
			return $order_id;
		}

		// if we didn't find the order, then it may be that this plugin was disabled and an order was placed in the interim
		$order = wc_get_order( $order_number );

		// _order_number was set, so this is not an old order, it's a new one that just happened to have post_id that matched the searched-for order_number
		if ( isset( $order->order_number ) ) {
			return 0;
		}

		return $order->id;
	}


	/**
	 * Verify that all the required fields for the API are populated
	 *
	 * @since 1.0
	 * @return boolean
	 */
	private function is_active() {

		return $this->api_endpoint && $this->api_username && $this->api_password && $this->company_code && $this->division_no && $this->price_level;
	}


} // end \WC_Sage_ERP_Connector_Integration class
