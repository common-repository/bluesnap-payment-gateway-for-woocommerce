<?php
/**
 * WordPress Plugin Boilerplate Core Functions
 *
 * General core functions available on both the front-end and admin.
 *
 * @author      SAU/CAL
 * @category    Core
 * @package     Woocommerce_Bluesnap_Gateway/Functions
 * @version     1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include core functions (available in both admin and frontend).
require 'woocommerce-bluesnap-gateway-template-functions.php';

/**
 * Get list of currencies supported by Bluesnap, same key,value.
 * @return array
 */
function get_bluesnap_supported_currency_list() {
	$rates = array();
	return check_if_bluesnap_settings_screen() ? apply_filters( 'get_bluesnap_supported_currency_list', $rates ) : $rates;
}

/**
 * Checks if we are on bluesnap setting screen.
 *
 * @return bool
 */
function check_if_bluesnap_settings_screen() {
	// Get current tab/section.
	$current_tab     = isset( $_GET['tab'] ) ? sanitize_title( wp_unslash( $_GET['tab'] ) ) : ''; // WPCS: input var okay, CSRF ok.
	$current_section = isset( $_GET['section'] ) ? sanitize_title( wp_unslash( $_GET['section'] ) ) : ''; // WPCS: input var okay, CSRF ok.
	return ( 'checkout' === $current_tab && 'bluesnap' === $current_section );
}

function bluesnap_format_decimal( $amount, $currency = null ) {

	if ( empty( $currency ) ) {
		$currency = get_woocommerce_currency();
	}
	$decimals = 2;
	switch ( $currency ) {
		case 'CLP':
		case 'JPY':
		case 'ISK':
		case 'KRW':
		case 'VND':
		case 'XOF':
			$decimals = 0;
			break;
	}

	return wc_format_decimal( $amount, $decimals );
}
