<?php
/**
 * @author   SAU/CAL
 * @category Class
 * @package  Woocommerce_Bluesnap_Gateway/Classes
 * @version  1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BlueSnap API Exception
 *
 * Class WC_Bluesnap_API_Exception
 */
class WC_Bluesnap_API_Exception extends Exception {

	/**
	 * WC_Bluesnap_API_Exception constructor.
	 *
	 * @param WP_Error|array $error
	 */
	public function __construct( $error ) {
		if ( is_wp_error( $error ) ) {
			parent::__construct( $error->get_error_message(), intval( $error->get_error_code() ) );
		} else {
			parent::__construct( $this->get_transaction_error_message( $error ), intval( $error['response']['code'] ) );
		}
	}

	/**
	 * Retrieve comprehensible error message gor the user.
	 *
	 * @param string $error Json Message coming from BlueSnap.
	 *
	 * @return string
	 */
	protected function get_transaction_error_message( $error ) {
		$body = json_decode( $error['body'], true );

		if ( ! $body ) {
			//todo: escalate error to site administration?
			// if 401, wrong credentials, what to do?
			return $error['response']['message'];
		}

		if ( ! empty( $body['message'] ) ) {
			$messages = $body['message'];
		} else if ( isset( $body['errorDescription'] ) ) {
			$messages = array(
				array(
					'code' => $body['errorCode'],
					'errorName' => $body['errorDescription'],
					'description' => $body['errorDescription'],
				),
			);
		}

		$error_messages = array();
		$messages       = isset( $messages ) ? $messages : array();

		foreach ( $messages as $message ) {
			if ( empty( $message['code'] ) ) {
				continue;
			}

			$error_messages[] = WC_Bluesnap_Errors::get_error( $message['code'], $message['errorName'], $message['description'] );
		}
		return implode( '<br>', $error_messages );
	}
}

