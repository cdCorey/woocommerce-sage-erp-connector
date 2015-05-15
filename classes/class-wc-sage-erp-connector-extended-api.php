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
 * @copyright   Copyright (c) 2013-2015, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Use the Sage Extended API to create a postcode inside the Sage ERP database
 *
 * @since 1.0
 */
class WC_Sage_ERP_Connector_Extended_API {

	/** @var object the SOAP connection resource */
	private $connection;

	/** @var string the endpoint host */
	private $endpoint;


	/**
	 * Set the endpoint for the API
	 *
	 * @since 1.0
	 * @return \WC_Sage_ERP_Connector_Extended_API
	 */
	public function __construct() {

		// the endpoint must be set by a custom plugin
		$this->endpoint = apply_filters( 'wc_sage_erp_connector_extended_api_endpoint', '' );
	}


	/**
	 * Creates the given postcode
	 *
	 * @param string $postcode postal code to add
	 * @param string $city city name
	 * @param string $state postal code abbreviation
	 * @param string $country two or three character country code
	 * @throws SoapFault
	 * @return bool true if the insert was successful, false otherwise
	 */
	public function create_postcode( $postcode, $city, $state, $country ) {

		// bail if endpoint isn't set
		if ( ! $this->endpoint )
			return false;

		// setup location data to insert
		$location = apply_filters( 'wc_sage_erp_connector_extended_api_location', array(
			'PostCode'    => $postcode,
			'City'        => ucwords( strtolower( $city ) ),
			'StateCode'   => ( ! empty( $state ) ) ? $state : 'XX',
			'CountryCode' => $country,
			'APIKey'      => '',
		) );

		// try to insert the location
		return $this->get_connection()->CreateZip( $location );
	}


	/**
	 * Connect to the web service and return the connection
	 *
	 * @since 0.1
	 * @throws SoapFault for the following conditions:
	 *         Invalid username/password
	 *         WSDL error/unavailable (note that XDebug must be disabled otherwise
	 *         this becomes a non-catchable fatal.  This can be done with xdebug_disable()
	 *         followed by xdebug_enable())
	 * @return SoapClient connection to the web service
	 */
	private function get_connection() {

		if ( is_object( $this->connection ) ) {
			return $this->connection;
		}

		$enable_xdebug = ( function_exists( 'xdebug_is_enabled' ) && xdebug_is_enabled() ) ? true : false;

		// disable xdebug
		if ( function_exists( 'xdebug_disable' ) ) {
			xdebug_disable();
		}

		// setup a new SoapClient and don't cache the WSDL
		$this->connection = @new SoapClient( $this->endpoint, array( 'cache_wsdl' => 'WSDL_CACHE_NONE' ) );

		// enable xdebug
		if ( $enable_xdebug && function_exists( 'xdebug_enable' ) ) {
			xdebug_enable();
		}

		return $this->connection;
	}


}
