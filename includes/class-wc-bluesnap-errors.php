<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that handles Bluesnap payment method.
 *
 * @extends WC_Bluesnap_Gateway
 *
 * @since 1.0.0
 */
class WC_Bluesnap_Errors {
	private static $hpf_errors = null;
	private static $api_errors = null;
	public static function init() {
		self::$hpf_errors = array(
			'10'    => array(
				'ccn' => __( 'Please enter a valid credit card number', 'woocommerce-bluesnap-gateway' ),
				'cvv' => __( 'Please enter the CVV/CVC of your card', 'woocommerce-bluesnap-gateway' ),
				'exp' => __( "Please enter your credit card's expiration date", 'woocommerce-bluesnap-gateway' ),
			),
			'001'   => __( 'Please enter a valid credit card number', 'woocommerce-bluesnap-gateway' ),
			'002'   => __( 'Please enter the CVV/CVC of your card', 'woocommerce-bluesnap-gateway' ),
			'003'   => __( "Please enter your credit card's expiration date", 'woocommerce-bluesnap-gateway' ),
			'22013' => __( 'Your payment could not be processed at this time as this card brand is not supported. Please select a new payment method and try again.', 'woocommerce-bluesnap-gateway' ),
			'14040' => __( 'Token is expired. Please try again.', 'woocommerce-bluesnap-gateway' ),
			'14041' => __( 'Could not find token', 'woocommerce-bluesnap-gateway' ),
			'14042' => __( 'Token is not associated with a payment method, please verify your client integration or contact BlueSnap support', 'woocommerce-bluesnap-gateway' ),
			'400'   => __( 'Token is expired. Please try again.', 'woocommerce-bluesnap-gateway' ),
			'403'   => __( 'Internal server error please try again later', 'woocommerce-bluesnap-gateway' ),
			'404'   => __( 'Internal server error please try again later', 'woocommerce-bluesnap-gateway' ),
			'500'   => __( 'Internal server error please try again later', 'woocommerce-bluesnap-gateway' ),

			// 3D Secure specific
			'14100' => __( '3D Secure is not enabled', 'woocommerce-bluesnap-gateway' ),
			'14101' => __( 'Secure authentication has failed. Please try again.', 'woocommerce-bluesnap-gateway' ),
			'14102' => __( '3D Secure object is missing required fields.', 'woocommerce-bluesnap-gateway' ),
			'14104' => __( 'Could not find selected card', 'woocommerce-bluesnap-gateway' ),
		);

		self::$api_errors = array(
			'14002'                        => __( 'Your payment could not be processed at this time. Please make sure the card information was entered correctly and resubmit. If the problem persists, please contact your credit card company to authorize the purchase.', 'woocommerce-bluesnap-gateway' ),
			'14002|SYSTEM_TECHNICAL_ERROR' => __( 'Your payment could not be processed at this time. Please try again later.', 'woocommerce-bluesnap-gateway' ),
			'14016'                        => __( 'Unfortunately your selected Card brand and Currency combination is not available. Please try a different card or contact our support team.', 'woocommerce-bluesnap-gateway' ),
		);

		ksort( self::$api_errors, SORT_NATURAL );
	}

	public static function get_hpf_errors() {
		return apply_filters( 'wc_gateway_bluesnap_hpf_errors', self::$hpf_errors );
	}

	private static function get_api_errors() {
		return apply_filters( 'wc_gateway_bluesnap_api_errors', self::$api_errors );
	}

	public static function get_error( $error_code, $error_name = '', $default = '' ) {
		$candidate = null;

		$override = apply_filters( 'wc_gateway_bluesnap_get_error_override', null, $error_code, $error_name, $default );

		if ( ! is_null( $override ) ) {
			return $override;
		}

		foreach ( self::get_api_errors() as $key => $message ) {
			$key = explode( '|', $key );
			if ( $key[0] != $error_code ) {
				if ( is_null( $candidate ) ) {
					continue;
				} else {
					break;
				}
			}

			if ( ! isset( $key[1] ) ) {
				$candidate = $message;
			} elseif ( ! empty( $error_name ) && $key[1] == $error_name ) {
				$candidate = $message;
			}
		}

		return is_string( $candidate ) ? $candidate : $default;
	}
}

WC_Bluesnap_Errors::init();
