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
 * @package     WC-Sage-ERP-Connector/Dependencies
 * @author      SkyVerge
 * @copyright   Copyright (c) 2013, SkyVerge, Inc.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Dependency Checker Class
 *
 * Checks if WooCommerce is enabled and required PHP extensions are loaded
 *
 * @since 1.0
 */
class WC_Sage_ERP_Connector_Dependencies {


	/** @var array PHP extensions required for plugin to function */
	private static $dependencies = array( 'soap' );


	/**
	 * Adds an admin notice if required PHP extensions are not installed
	 *
	 * @access public
	 * @since  1.0
	 */
	public static function check_dependencies() {

		$missing_extensions = self::get_missing_dependencies();

		if ( count( $missing_extensions ) > 0 ) {

			$message = sprintf(
				_n( 'WooCommerce Sage ERP Connector requires the %s PHP extension to function.  Contact your host or server administrator to configure and install the missing extension.',
					'WooCommerce Sage ERP Connector requires the following PHP extensions to function: %s.  Contact your host or server administrator to configure and install the missing extensions.',
					count( $missing_extensions ), WC_Sage_ERP_Connector::TEXT_DOMAIN ),
			  '<strong>' . implode( ', ', $missing_extensions ) . '</strong>'
			);

			echo '<div class="error"><p>' . $message . '</p></div>';
		}
	}


	/**
	 * Checks if given PHP extensions are loaded
	 *
	 * @access public
	 * @since  1.0
	 * @return array
	 */
	private static function get_missing_dependencies() {

		$missing_dependencies = array();

		foreach ( self::$dependencies as $extension ) {
			if ( ! extension_loaded( $extension ) )
				$missing_dependencies[] = $extension;
		}

		return $missing_dependencies;
	}


	/**
	 * Checks if WooCommerce is active
	 *
	 * @access public
	 * @since  1.0
	 * @return bool
	 */
	public static function is_woocommerce_active() {

		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() )
			$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

		return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
	}


} // end \WC_Sage_ERP_Connector_Dependencies class
