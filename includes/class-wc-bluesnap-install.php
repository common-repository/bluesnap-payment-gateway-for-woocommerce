<?php
/**
 * Installation related functions and actions.
 *
 * @author   SAU/CAL
 * @category Admin
 * @package  Woocommerce_Bluesnap_Gateway/Classes
 * @version  1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Bluesnap_Install Class.
 */
class WC_Bluesnap_Install {

	/**
	 * Install WC_Bluesnap.
	 */
	public static function install() {
		// Trigger action
		do_action( 'woocommerce_bluesnap_gateway_installed' );
	}
}

register_activation_hook( WC_BLUESNAP_PLUGIN_FILE, array( 'WC_Bluesnap_Install', 'install' ) );
