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
 * @package     WC-Sage-ERP-Connector/Exporter
 * @author      SkyVerge
 * @copyright   Copyright (c) 2013, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Export Class
 *
 * Handles exporting orders to Sage ERP
 *
 * @since 1.0
 */
class WC_Sage_ERP_Connector_Exporter {


	/** @var object WC_Sage_ERP_Connector_API instance */
	private $api;

	/** @var object WC_Sage_ERP_Connector_Extended_API instance */
	private $extended_api;

	/** @var array order IDs to export */
	public $order_ids;

	/** @var string default division no to import customers/orders under */
	private $default_ar_division_no;

	/** @var string default salesperson no to import orders under */
	private $default_salesperson_no;

	/** @var string default price level to import customers/ sales order lines under */
	private $default_price_level;



	/**
	 * Setup the exporter
	 *
	 * @since 1.0
	 * @param array $order_ids the order IDs to export
	 * @return \WC_Sage_ERP_Connector_Exporter
	 */
	public function __construct( $order_ids ) {
		global $wc_sage_erp_connector;

		// set default AR Division Number
		$this->default_ar_division_no = $wc_sage_erp_connector->integration->division_no;

		// set default Salesperson Number
		$this->default_salesperson_no = $wc_sage_erp_connector->integration->salesperson_no;

		// set default Price Level
		$this->default_price_level = $wc_sage_erp_connector->integration->price_level;

		// handle single order exports
		if ( ! is_array( $order_ids ) ) {
			$order_ids = array( $order_ids );
		}

		$this->order_ids = $order_ids;
	}


	/**
	 * Export the orders and display an admin notice showing the # of successful/failed exports and any error messages
	 *
	 * @since 1.0
	 */
	public function export() {
		global $wc_sage_erp_connector;

		$success = $failure = 0;

		foreach ( $this->order_ids as $order_id ) {

			$order = new WC_Order( $order_id );

			$export = $this->export_order( $order );

			// keep track of the number of successful/failed exports
			if ( $export ) {
				$success++;
			} else {
				$failure++;
			}
		}

		// add an admin message displaying the number of successful exports
		if ( $success ) {
			$wc_sage_erp_connector->messages->add_message( sprintf( _n( 'Order exported to Sage ERP.', '%s orders exported to Sage ERP.', $success, WC_Sage_ERP_Connector::TEXT_DOMAIN ), number_format_i18n( $success ) ) );
		}

		// add an admin error message displaying the number of failed exports
		if ( $failure ) {
			$wc_sage_erp_connector->messages->add_error( sprintf( _n( 'Order failed export to Sage ERP.', '%s orders failed export to Sage ERP.', $failure, WC_Sage_ERP_Connector::TEXT_DOMAIN ), number_format_i18n( $failure ) ) );
		}

		// persist errors / messages to display on next page load
		$wc_sage_erp_connector->messages->set_messages();
	}


	/**
	 * Export the identified order to Sage.  Previously exported orders
	 * are ignored.  The basic procedure is:
	 *
	 * + create an Sage customer record for guest purchases, or customer accounts that haven't been exported yet
	 *   for customer accounts, mark the WordPress account meta data
	 *
	 * + otherwise for an existing exported customer, update the Sage customer account with the latest info
	 *
	 * + create the Sage sales order record
	 * + update the woocommerce sales order meta with the Sage info
	 *
	 * There is the possibility that this script times out in the middle
	 * of the export process. There's really only three possible scenarios:
	 *
	 * 1. timeout after creating the Sage customer but before setting
	 *    the wordpress meta fields.  Unlikely.  Worst-case scenario: on the next
	 *    export a duplicate customer would be created
	 * 2. timeout after creating the Sage order but before setting the
	 *    wordpress order meta fields.  Unlikely.  Worst-case scenario:
	 *    on the next export a duplicate order would be created; not great
	 *    but hopefully someone would notice.
	 * 3. The only really bad ones would be a timeout in between the
	 *    setting/updating of meta data, in which case the system might
	 *    be left in an inconsistent state, however the order the meta
	 *    updates are performed in is chosen to reduce that likelihood
	 *
	 * @param object $order the WC_Order object to export
	 *
	 * @return bool true on success, false on failure
	 */
	private function export_order( $order ) {
		global $wc_sage_erp_connector;

		// if this order was already exported, ignore it
		if ( isset( $order->wc_sage_erp_exported ) && $order->wc_sage_erp_exported ) {
			return 0;
		}

		// TODO: restrict export to certain order statuses

		try {

			// get the sales order object, this will also create or update the associated customer as needed
			$sales_order = $this->get_sales_order( $order );

			// create the sales order
			$order_number = $this->get_api()->create_sales_order( $sales_order );

			// update the woocommerce sales order
			add_post_meta( $order->id, '_order_number', $order_number );
			update_post_meta( $order->id, '_wc_sage_erp_exported', 1 );

			// add order note
			$order->add_order_note( __( 'Order exported to Sage ERP.', WC_Sage_ERP_Connector::TEXT_DOMAIN ) );

		} catch ( SoapFault $e ) {

			// check for a Postcode insertion error (at either customer or sales order level), and try to recover by adding it via the extended API
			if ( isset( $e->faultcode ) && 'a:CI_NOF' === $e->faultcode && isset( $e->faultstring ) && (
				stripos( $e->faultstring, 'Could not set AR_Customer_bus column ZipCode' ) !== false ||
				stripos( $e->faultstring, 'Could not set SO_SalesOrder_Bus column ShipToZipCode' ) !== false )
	 		) {

				$type = ( false !== stripos( $e->faultstring, 'Could not set SO_SalesOrder_Bus column ShipToZipCode' ) ) ? 'shipping' : 'billing';

				if ( $this->create_postcode( $order, $type ) ) {

					// creation successful, try to export the order again

					// use the CustomerNo that was generated
					if ( isset( $e->data['CustomerNo'] ) )
						$order->CustomerNo = $e->data['CustomerNo'];

					// use the SalesOrderNo that was generated
					if ( isset( $e->data['SalesOrderNo'] ) )
						$order->SalesOrderNo = $e->data['SalesOrderNo'];

					$this->export_order( $order );

				} else {

					// creation failed
					$wc_sage_erp_connector->messages->add_error( sprintf( __( 'Export Failure - Order %s, Message : %s (%s)', WC_Sage_ERP_Connector::TEXT_DOMAIN ), $order->id, $e->getMessage(), ( isset( $e->detail->MasFault ) ) ? $e->detail->MasFault->ErrorCode . ' : ' . $e->detail->MasFault->ErrorMessage : __( 'N/A', WC_Sage_ERP_Connector::TEXT_DOMAIN ) ) );

					return false;
				}

			} else {

				// non-postcode failure, add error message
				$wc_sage_erp_connector->messages->add_error( sprintf( 'Export Failure - Order %s (ERP Sales Order No: %s), Message : %s (%s)',
					$order->id,
					( isset( $e->data['SalesOrderNo'] ) ) ? $e->data['SalesOrderNo'] : __( 'N/A', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
					$e->getMessage(),
					( isset( $e->detail->MasFault ) ) ? $e->detail->MasFault->ErrorCode . ' : ' . $e->detail->MasFault->ErrorMessage : __( 'N/A', WC_Sage_ERP_Connector::TEXT_DOMAIN )
				) );

				// add failure order note
				$order->add_order_note( sprintf( __( 'Failed order export to Sage ERP (Sales Order No %s, Message: %s)', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
					( isset( $e->data['SalesOrderNo'] ) ) ? $e->data['SalesOrderNo'] : __( 'N/A', WC_Sage_ERP_Connector::TEXT_DOMAIN ),
					$e->getMessage()
				) );

				return false;
			}
		}

		return true;
	}


	/**
	 * Get the sales order object in the format required by the Sage API
	 *
	 * @since 1.0
	 * @param object $order the \WC_Order object
	 * @return object the sales order object
	 */
	private function get_sales_order( $order ) {

		// set the sales order object in the format required by the API
		$sales_order = new stdClass();

		/* Set required sales order fields */

		// get the Sage customer
		$customer = $this->get_customer( $order );

		// fetch new sales order number if one isn't already set
		if ( empty( $order->SalesOrderNo ) ) {
			$sales_order->SalesOrderNo = $this->get_api()->get_next_sales_order_no();
		} else {
			$sales_order = $order->SalesOrderNo; // allow the SalesOrderNo to be set via the order, which can happen when recovering from errors
		}

		// get the Sage ERP customer number
		$sales_order->CustomerNo   = $customer->CustomerNo;

		// set division number
		$sales_order->ARDivisionNo = ( ! empty( $customer->ARDivisionNo ) ) ? $customer->ARDivisionNo : $this->default_ar_division_no;

		// set Salesperson Number if default is set
		if ( ! empty( $this->default_salesperson_no ) ) {
			$sales_order->SalespersonNo = $this->default_salesperson_no;
		}

		// Override default if salesperson number is set for customer
		if ( ! empty( $customer->SalespersonNo ) ) {
			$sales_order->SalespersonNo = $customer->SalespersonNo;
		}

		// setup line items
		foreach ( $order->get_items() as $order_item_key => $order_item ) {

			// get product
			$product = $order->get_product_from_item( $order_item );

			// setup line item
			$sales_order_line_item = new stdClass();

			// add item code
			$sales_order_line_item->ItemCode = $product->get_sku();

			// add quantity ordered
			$sales_order_line_item->QuantityOrdered = $order_item['qty'];

			// allow modification of line item
			$sales_order_line_item = apply_filters( 'wc_sage_erp_connector_sales_order_line_item', $sales_order_line_item, $product, $order_item, $order, $this );

			// add to order lines
			$sales_order->Lines[] = $sales_order_line_item;
		}

		// allow modification of entire sales order
		return apply_filters( 'wc_sage_erp_connector_sales_order', $sales_order, $order, $this );
	}


	/**
	 * Setup the customer object and create or update the customer in Sage
	 *
	 * @since 1.0
	 * @param object $order the \WC_Order object
	 * @return object the customer object
	 */
	private function get_customer( $order ) {

		// setup customer object
		$customer = new stdClass();

		// set default division number
		if ( ! empty( $this->default_ar_division_no ) ) {
			$customer->ARDivisionNo = $this->default_ar_division_no;
		}

		// set default price level
		if ( ! empty( $this->default_price_level ) ) {
			$customer->PriceLevel = $this->default_price_level;
		}

		// set default salesperson number (this is *required* when adding a customer)
		if ( ! empty( $this->default_salesperson_no ) ) {
			$customer->SalespersonNo = $this->default_salesperson_no;
		}

		// get the customer number for registered customers
		if ( $order->user_id ) {

			$customer->CustomerNo = get_user_meta( $order->customer_user, '_wc_sage_erp_customer_no', true );

			// TODO: consider setting ARDivisionNo here from user meta

		} else {

			// otherwise for a guest purchase (has user_id = 0), check whether we've already attempted to export this order (and failed) and reuse the customer_no if so
			if ( isset( $order->wc_sage_erp_customer_no ) && $order->wc_sage_erp_customer_no ) {
				$customer->CustomerNo = $order->wc_sage_erp_customer_no;
			}
		}

		// allow the CustomerNo to be set via the order, which can happen when recovering from errors
		if ( ! empty( $order->CustomerNo ) ) {
			$customer->CustomerNo = $order->CustomerNo;
		}

		/* No other additional information is set as Sage does not require anything else to create a customer */

		// allow modification of customer
		$customer = apply_filters( 'wc_sage_erp_connector_customer', $customer, $order, $this );

		// create the customer if they're a new customer *or* if the order object has a customer number, which indicates we're recovering from an error
		// otherwise update the customer
		if ( empty( $customer->CustomerNo ) || ! empty( $order->CustomerNo ) ) {
			$customer = $this->get_api()->create_customer( $customer ); // create the customer
		} else {
			$customer = $this->get_api()->update_customer( $customer ); // update the customer
		}

		// save sage info to user if registered
		if ( $order->user_id ) {

			// customer number
			update_user_meta( $order->user_id, '_wc_sage_erp_customer_no', $customer->CustomerNo );

			// division number
			update_user_meta( $order->user_id, '_wc_sage_erp_division_no', $customer->ARDivisionNo );

			// mark as having been created
			update_user_meta( $order->user_id, '_wc_sage_erp_exported', 1 );
		}

		// add CustomerNo/DivisionNo as order meta so it can be looked up even for guest purchases
		update_post_meta( $order->id, '_wc_sage_erp_customer_no', $customer->CustomerNo );
		update_post_meta( $order->id, '_wc_sage_erp_division_no', $customer->ARDivisionNo );

		return $customer;
	}


	/**
	 * Only available in test mode:
	 * Un-export the sales order as best we can by:
	 * * resetting the woocommerce order fields
	 * * resetting the woocommerce customer fields (if this was the last exported order for the customer)
	 * * deleting the MAS order record
	 * * deleting the MAS customer record (if this was the last exported order for the customer)
	 *
	 * @param object $order identifies the order to un-export
	 * @return bool true on success, false on failure
	 */
	private function unexport( $order ) {
		global $wc_sage_erp_connector;

		try {

			// if this order has not been exported, ignore it
			if ( ! isset( $order->wc_sage_erp_exported ) || ! $order->wc_sage_erp_exported ) {
				return false;
			}

			// if order has an attached MAS order number, delete from MAS
			if ( isset( $order->order_number ) && $order->order_number ) {
				$this->get_api()->delete_sales_order( $order->order_number );
			}

			// reset woocommerce order record
			delete_post_meta( $order->id, '_wc_sage_erp_division_no' );
			delete_post_meta( $order->id, '_wc_sage_erp_customer_no' );
			delete_post_meta( $order->id, '_order_number' );
			update_post_meta( $order->id, '_wc_sage_erp_exported', 0 );


			$unexport_customer = true;

			// is this a non-guest purchase?
			if ( $order->user_id ) {

				// check whether this user has any other exported orders
				$args = array(
					'numberposts' => - 1,
					'meta_key'    => '_customer_user',
					'meta_value'  => $order->user_id,
					'post_type'   => 'shop_order',
					'post_status' => 'publish'
				);
				$customer_orders = get_posts( $args );

				if ( is_array( $customer_orders ) && count( $customer_orders ) > 1 ) {

					foreach ( $customer_orders as $customer_order ) {

						// check other orders
						if ( $customer_order->ID != $order->id ) {

							// customer has at least one other exported order record, so we won't unexport the customer for now
							if ( get_post_meta( $customer_order->ID, '_wc_sage_erp_exported', true ) ) {

								$unexport_customer = false;
								break;
							}
						}
					}
				}
			}

			// if we are able to unexport the the customer and have a MAS customer number, delete from MAS
			if ( $unexport_customer && isset( $order->wc_sage_erp_customer_no ) && $order->wc_sage_erp_customer_no ) {
				$this->get_api()->delete_customer( $order->wc_sage_erp_customer_no );
			}

			// reset woocommerce user record
			if ( $order->user_id && $unexport_customer ) {
				delete_user_meta( $order->user_id, '_wc_sage_erp_division_no' );
				delete_user_meta( $order->user_id, '_wc_sage_erp_customer_no' );
				delete_user_meta( $order->user_id, '_wc_sage_erp_exported' );
			}

		} catch ( SoapFault $e ) {

			$wc_sage_erp_connector->messages->add_error( sprintf( 'Order %s: %s (%s)', $order->id, $e->getMessage(), ( isset( $e->detail->MasFault ) ) ? $e->detail->MasFault->ErrorCode . ' - ' . $e->detail->MasFault->ErrorMessage : '' ) );

			return false;
		}

		return true;
	}


	/**
	 * Create a postcode using the Sage extended API (this will return false if not available for use)
	 *
	 * @since 1.0
	 * @param object $order the \WC_Order object
	 * @param string $type the type of postcode to create, either `billing` or `shipping`
	 * @return bool true if the postcode was created, false otherwise
	 */
	private function create_postcode( $order, $type = 'billing' ) {
		global $wc_sage_erp_connector;

		if ( ! is_object( $this->extended_api ) ) {

			require_once( $wc_sage_erp_connector->get_plugin_path() . '/classes/class-wc-sage-erp-connector-extended-api.php' );

			$this->extended_api = new WC_Sage_ERP_Connector_Extended_API();
		}

		if ( 'billing' === $type ) {
			return $this->extended_api->create_postcode( $order->billing_postcode, $order->billing_city, $order->billing_state, $order->billing_country );
		} else {
			return $this->extended_api->create_postcode( $order->shipping_postcode, $order->shipping_city, $order->shipping_state, $order->shipping_country );
		}
	}


	/**
	 * Lazy-load the API instance
	 *
	 * @since 1.0
	 */
	private function get_api() {
		global $wc_sage_erp_connector;

		if ( is_object( $this->api ) ) {
			return $this->api;
		}

		require_once( $wc_sage_erp_connector->get_plugin_path() . '/classes/class-wc-sage-erp-connector-api.php' );

		// init API
		$this->api = new WC_Sage_ERP_Connector_API( $wc_sage_erp_connector->integration->api_endpoint,$wc_sage_erp_connector->integration->api_username, $wc_sage_erp_connector->integration->api_password, $wc_sage_erp_connector->integration->company_code );

		return $this->api;
	}


} // end \WC_Sage_ERP_Connector_Exporter class
