<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://saucal.com
 * @since             1.0.0
 * @package           Woocommerce_Bluesnap_Gateway
 *
 * @wordpress-plugin
 * Plugin Name:          BlueSnap Payment Gateway for WooCommerce
 * Plugin URI:           https://bluesnap.com/
 * Description:          WooCommerce gateway module to accept credit/debit card payments worldwide
 * Version:              3.1.0
 * Author:               SAU/CAL
 * Author URI:           https://saucal.com
 * License:              GPL-2.0+
 * License URI:          http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:          woocommerce-bluesnap-gateway
 * Domain Path:          /i18n/languages
 * Tested up to:         6.6
 * WC tested up to:      8.5.2
 * WC requires at least: 3.7.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Declare HPOS compatibility.
add_action(
	'before_woocommerce_init',
	function() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

if ( function_exists( 'WC_Bluesnap' ) ) {

	add_action(
		'admin_notices',
		function() {
			?>
			<div class="notice notice-error">
				<p><strong><?php echo sprintf( esc_html__( 'You can\'t enable two versions of the same plugin. BlueSnap Payment Gateway for WooCommerce v%s plugin is enabled.', 'woocommerce-bluesnap-gateway' ), WC_BLUESNAP_VERSION ); //phpcs:ignore ?></strong></p>
			</div>
			<?php
		}
	);

} else {

	define( 'WC_BLUESNAP_PLUGIN_FILE', __FILE__ );
	require_once 'class-woocommerce-bluesnap-gateway.php';

	/**
	 * Main instance of Woocommerce_Bluesnap_Gateway.
	 *
	 * Returns the main instance of WC_Bluesnap to prevent the need to use globals.
	 *
	 * @since  1.0.0
	 * @return Woocommerce_Bluesnap_Gateway
	 */

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName
	function WC_Bluesnap() {
		return Woocommerce_Bluesnap_Gateway::instance();
	}

	// Global for backwards compatibility.
	$GLOBALS['woocommerce_bluesnap_gateway'] = WC_Bluesnap();
}
