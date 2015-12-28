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
 * @package     WC-Sage-ERP-Connector/API
 * @author      SkyVerge
 * @copyright   Copyright (c) 2013-2015, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Sage eBusiness Web Services API Class
 *
 * Provides a simplified wrapper for to the Sage API
 *
 * @since 0.1
 */
class WC_Sage_ERP_Connector_API {


	/** @var string the endpoint URL for the eBusiness Web Services API */
	private $endpoint;

	/** @var string the API username */
	private $username;

	/** @var string the API password */
	private $password;

	/** @var string company code to import orders into */
	private $company_code;

	/** @var \SoapClient connection object for the API */
	private $client;


	/**
	 * Initialize the API object
	 *
	 * @since 0.1
	 * @param string $endpoint the endpoint URL
	 * @param string $username connection username
	 * @param string $password connection password
	 * @param string $company_code identifies the company
	 * @return \WC_Sage_ERP_Connector_API
	 */
	public function __construct( $endpoint, $username, $password, $company_code ) {

		// set class members
		$this->endpoint       = $endpoint;
		$this->username       = $username;
		$this->password       = $password;
		$this->company_code   = $company_code;
	}


	/**
	 * Returns the customer identified by the given customer no
	 *
	 * @since 0.1
	 * @param string $ar_division_no the ARDivisionNo for the customer
	 * @param string $customer_no the customer number
	 * @param bool $unpack_udf true to unpack custom fields into native PHP object, false otherwise
	 * @throws Exception SoapFault for the following conditions:
	 *         + Invalid username/password
	 *         + WSDL error/unavailable
	 *         + No Access - you are not authorized to perform this action
	 *         + CI_NoKey - Customer does not exist
	 * @return object customer object
	 */
	public function get_customer( $ar_division_no, $customer_no, $unpack_udf = true ) {

		$response = $this->get_connection()->GetCustomer( array(
				'logon'        => $this->get_logon(),
				'companyCode'  => $this->company_code,
				'customerNo'   => $customer_no,
				'arDivisionNo' => $ar_division_no,
			)
		);

		$customer = $response->GetCustomerResult;

		// Handle custom UDF fields if present
		if ( $unpack_udf && isset( $customer->OtherFields->Field ) ) {

			// merge into customer object
			$customer = (object) array_merge( (array)$customer, $this->unpack_custom_fields( $customer->OtherFields->Field ) );

			// remove them
			unset( $customer->OtherFields );
		}

		return $customer;
	}


	/**
	 * Gets the next customer number, which can be used with the create_customer() method.  Note that this call has
	 * the side effect of advancing the customer number on the endpoint regardless of whether a subsequent create_customer()
	 * call is invoked.  This means that there is no danger of a race condition between two different clients creating
	 * customers.
	 *
	 * @since 0.1
	 * @return string next available customer number
	 * @throws SoapFault for the following conditions: No Access - you are not authorized to perform this action
	 */
	public function get_next_customer_no() {

		$response = $this->get_connection()->GetNextCustomerNo( array( 'logon' => $this->get_logon(), 'companyCode' => $this->company_code ) );

		return $response->GetNextCustomerNoResult;
	}


	/**
	 * Create a new customer
	 *
	 * @since 0.1
	 * @param object $customer customer stdClass object in the format required by API
	 * @throws Exception SoapFault for the following conditions:
	 *         + Unknown country code/invalid zip code format/invalid email address format
	 *         + CI_CharLength: The Default Payment Type is greater than 5 character(s)
	 *         + WS_AlreadyExists: Record already exists if the customer_no is already in use
	 * @return string the customer number of the newly created customer
	 */
	public function create_customer( $customer ) {

		// if customer number not provided, get a new number
		if ( empty( $customer->CustomerNo ) )
			$customer->CustomerNo = $this->get_next_customer_no();

		// SalespersonDivisionNo - this scopes the SalespersonNo and will default to the ARDivisionNo

		// add any custom fields
		if ( ! empty( $customer->_CustomFields ) )

			if ( empty( $customer->OtherFields ) ) {
				$customer->OtherFields = new stdClass();
			}

			$customer->OtherFields->Field = $this->pack_custom_fields( $customer->_CustomFields, array() );

		try {

			// no return response for creating a customer
			$this->get_connection()->CreateCustomer( array(
					'logon'       => $this->get_logon(),
					'companyCode' => $this->company_code,
					'customer'    => $customer,
				)
			);

		} catch ( Exception $e ) {

			// add the CustomerNumber to the exception object so it can be used for error messages, etc.
			$e->data['CustomerNo'] = $customer->CustomerNo;

			throw $e;
		}

		return $customer;
	}


	/**
	 * Update the customer identified by customer number
	 *
	 * @since 0.1
	 * @param object $new_customer_record the new customer record in a stdClass object in the format required by API
	 * @throws Exception|SoapFault for the following conditions:
	 *         + Unknown country code/invalid zip code format/invalid email address format
	 *         + CI_CharLength: The Default Payment Type is greater than 5 character(s)
	 * @return object the updated customer record
	 */
	public function update_customer( $new_customer_record ) {

		// per the Sage API specs, first retrieve the customer to update (without custom fields merged)
		$existing_customer_record = $this->get_customer( $new_customer_record->ARDivisionNo, $new_customer_record->CustomerNo, false );

		// merge the new customer record into the old one
		$new_customer_record = (object) array_merge( (array) $existing_customer_record, (array) $new_customer_record );

		try {

			// no return response for updating a customer
			$this->get_connection()->UpdateCustomer( array(
					'logon'       => $this->get_logon(),
					'companyCode' => $this->company_code,
					'customer'    => $new_customer_record,
				)
			);

		} catch ( SoapFault $e ) {

			// add the CustomerNumber to the exception object so it can be used for error messages, etc.
			$e->data['CustomerNo'] = $new_customer_record->CustomerNo;

			throw $e;
		}

		return $new_customer_record;
	}


	/**
	 * Deletes the customer identified by $customer_no.
	 *
	 * @since 0.1
	 * @param string $customer_no the customer number to delete
	 * @throws SoapFault on failure
	 */
	public function delete_customer( $customer_no ) {

		// no return response for deleting a customer
		$this->get_connection()->DeleteCustomer( array(
				'logon'        => $this->get_logon(),
				'companyCode'  => $this->company_code,
				'arDivisionNo' => $this->ar_division_no,
				'customerNo'   => $customer_no
			)
		);
	}


	/**
	 * Returns the sales order identified by $sales_order_no
	 *
	 * @since 0.1
	 * @param string $sales_order_no the MAS sales order number
	 * @param bool $unpack_udf true to unpack custom order fields into native PHP objects, false otherwise
	 * @throws SoapFault for the following conditions:
	 *         Invalid username/password
	 *         WSDL error/unavailable
	 *         No Access - you are not authorized to perform this action
	 *         Customer does not exist
	 * @return object sales order
	 */
	public function get_sales_order( $sales_order_no, $unpack_udf = true ) {

		$response = $this->get_connection()->GetSalesOrder( array(
				'logon'        => $this->get_logon(),
				'companyCode'  => $this->company_code,
				'salesOrderNo' => $sales_order_no
			)
		);

		$sales_order = $response->GetSalesOrderResult;

		// Handle custom UDF fields if present by merging them into the sales order object
		if ( $unpack_udf && isset( $sales_order->OtherFields->Field ) ) {

			// merge into sales object
			$sales_order = (object) array_merge( (array) $sales_order, $this->unpack_custom_fields( $sales_order->OtherFields->Field ) );

			// remove them
			unset( $sales_order->OtherFields );
		}

		return $sales_order;
	}


	/**
	 * Gets the next sales order number, which can be used with the create_sales_order() method.  Note that this call
	 * has the side effect of advancing the order number on the endpoint regardless of whether a subsequent
	 * create_sales_order() call is invoked.  This means that there is no danger of a race condition between two
	 * different clients creating sales orders.
	 *
	 * @since 0.1
	 * @return string next available sales order number
	 * @throws SoapFault for the following conditions: No Access - you are not authorized to perform this action
	 */
	public function get_next_sales_order_no() {

		$response = $this->get_connection()->GetNextSalesOrderNo( array( 'logon' => $this->get_logon(), 'companyCode' => $this->company_code ) );

		return $response->GetNextSalesOrderNoResult;
	}


	/**
	 * Create a sales order
	 *
	 * @since 0.1
	 * @param object $sales_order the sales order information in a stdClass object in the format required by the API
	 * @throws Exception|SoapFault for the following conditions:
	 *         + CI_NOF - unknown customer number, unknown SKU
	 *         + WS_AlreadyExists - specified sales order no already exists
	 * @return string the sales order number of the newly-created sales order
	 */
	public function create_sales_order( $sales_order ) {

		// add custom fields
		if ( ! empty( $sales_order->_CustomFields ) ) {

			if ( empty( $sales_order->OtherFields ) ) {
				$sales_order->OtherFields = new stdClass();
			}

			$sales_order->OtherFields->Field = $this->pack_custom_fields( $sales_order->_CustomFields, array() );
		}

		try {

			// no return response for creating a sales order
			$response = $this->get_connection()->CreateSalesOrder( array(
					'logon'       => $this->get_logon(),
					'companyCode' => $this->company_code,
					'salesOrder'  => $sales_order
				)
			);

		} catch( SoapFault $e ) {

			// add the CustomerNumber to the exception object so it can be used for error messages, etc.
			$e->data['SalesOrderNo'] = $sales_order->SalesOrderNo;

			throw $e;
		}

		return $sales_order->SalesOrderNo;
	}


	/**
	 * Update sales order
	 *
	 * TODO : this method is incomplete
	 *
	 * @since 1.0
	 * @throws SoapFault on failure
	 */
	function update_sales_order( $sales_order_no, $value ) {

		// per the Web Services API document, first retrieve the sales order to update
		$sales_order = $this->get_sales_order( $sales_order_no, false );

		// no return response for updating a sales order
		$this->get_connection()->UpdateSalesOrder( array(
				'logon'        => $this->get_logon(),
				'companyCode'  => $this->company_code,
				'salesOrderNo' => $sales_order_no,
				'salesOrder'   => $value
			)
		);
	}


	/**
	 * Deletes the sales order identified by $sales_order_no.
	 *
	 * @since 1.0
	 * @param string $sales_order_no the sales order number
	 * @throws SoapFault on failure
	 */
	public function delete_sales_order( $sales_order_no ) {
		// no return response for deleting a sales order
		$this->get_connection()->DeleteSalesOrder( array(
				'logon'        => $this->get_logon(),
				'companyCode'  => $this->company_code,
				'salesOrderNo' => $sales_order_no
			)
		);
	}


	/** Helper methods ******************************************************/


	/**
	 * Unpack UDF custom fields and return them as an associative array
	 *
	 * @since 0.1
	 * @param array|object $fields array of UDF key/value field objects with members MasFieldName and Value
	 * @return array associative array composed of the key/value pairs found in $fields
	 */
	private function unpack_custom_fields( $fields ) {

		// annoyingly, rather than returning arrays with a single object, MAS returns just the single object
		if ( ! is_array( $fields ) )
			$fields = array( $fields );

		$result = array();

		foreach ( $fields as $field ) {
			$result[$field->MasFieldName] = $field->Value;
		}

		return $result;
	}


	/**
	 * Pack $data into the MAS custom field format, updating/adding them to
	 * $udf, which is then returned.
	 *
	 * @since 0.1
	 * @param array $data associative array of key/value pairs to add/update to $udf
	 * @param array $udf array of UDF key/value field objects with members MasFieldName and Value
	 * @return array of UDF key/value field objects with members MasFieldName and Value, composed of $data merged onto $udf
	 */
	private function pack_custom_fields( $data, $udf ) {

		foreach ( $data as $key => $value ) {
			$found = false;

			// update the UDF field if found
			foreach ( $udf as &$field ) {
				if ( $field->MasFieldName == $key ) {
					$field->Value = $value;
					$found = true;
					break;
				}
			}

			// UDF field not found, add it
			if ( ! $found ) {
				$udf[] = (object) array( 'MasFieldName' => $key, 'Value' => $value );
			}
		}

		return $udf;
	}


	/**
	 * Connect to the web service and return the connection
	 *
	 * @since 0.1
	 * @throws SoapFault for the following conditions:
	 *         Invalid username/password
	 *         WSDL error/unavailable (note that XDebug must be disabled otherwise this becomes a un-catchable fatal.  This can be done with xdebug_disable() followed by xdebug_enable() )
	 * @return SoapClient connection to the web service
	 */
	private function get_connection() {

		if ( is_object( $this->client ) )
			return $this->client;

		$enable_xdebug = ( function_exists( 'xdebug_is_enabled' ) && xdebug_is_enabled() ) ? true : false;

		// disable xdebug
		if ( function_exists( 'xdebug_disable' ) )
			xdebug_disable();

		// setup a new SoapClient and don't cache the WSDL
		$this->client = @new SoapClient( $this->endpoint, array( 'cache_wsdl' => 'WSDL_CACHE_NONE' ) );

		// enable xdebug
		if ( $enable_xdebug && function_exists( 'xdebug_enable' ) )
			xdebug_enable();

		return $this->client;
	}


	/**
	 * Returns a logon object containing a username and password
	 *
	 * @since 0.1
	 * @return object logon object with username/password
	 */
	private function get_logon() {

		$logon = new stdClass();
		$logon->Username = $this->username;
		$logon->Password = $this->password;

		return $logon;
	}


}
