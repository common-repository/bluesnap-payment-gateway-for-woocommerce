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
 * BlueSnap API communication
 *
 * Class WC_Bluesnap_API
 */
class WC_Bluesnap_API {

	/**
	 * API Endpoints.
	 */
	const API_DOMAIN_SANDBOX    = 'https://sandbox.bluesnap.com';
	const API_DOMAIN_PRODUCTION = 'https://ws.bluesnap.com';
	const ENDPOINT_API          = '/services/2/';

	const HOSTED_PAYMENT_JS_LIBRARY         = '/web-sdk/4/bluesnap.js';
	const HOSTED_PAYMENT_JS_LIBRARY_VERSION = '4.0';

	/**
	 * Hosted Payment Fields token service.
	 */
	const API_HPF_TOKEN = 'payment-fields-tokens';

	/**
	 * Transaction endpoint.
	 */
	const API_TRANSACTION     = 'transactions';
	const API_ALT_TRANSACTION = 'alt-transactions';

	/**
	 * Refund endpoint.
	 */
	const API_REFUND = 'transactions/{transaction_id}/refund';

	/**
	 * Vaulted Shoppers endpoint.
	 */
	const VAULTED_SHOPPERS = 'vaulted-shoppers';

	/**
	 * Currency rates endpoint.
	 */
	const CURRENCY_RATES = 'tools/currency-rates';

	/**
	 * 3D Secure Token endpoint.
	 */
	const API_3D_TOKEN = 'threeDSecure';

	/**
	 * Wallets endpoint
	 */
	const WALLETS = 'wallets';

	/**
	 * Subscriptions endpoint
	 */
	const SUBSCRIPTIONS = 'recurring/subscriptions';

	/**
	 * Subscriptions (on demand) endpoint
	 */
	const SUBSCRIPTIONS_ONDEMAND = 'recurring/ondemand';

	/**
	 * Api Version.
	 */
	const API_VERSION     = '2.0';
	const API_ACH_VERSION = '3.0';


	/**
	 * Get Username from settings.
	 * @return string
	 */
	public static function get_username() {
		return WC_Bluesnap()->get_option( 'api_username' );
	}

	/**
	 * Get Password from settings.
	 * @return string
	 */
	public static function get_password() {
		return WC_Bluesnap()->get_option( 'api_password' );
	}

	/**
	 * Authorization: Basic {Base64 encoding of 'username:password'}.
	 * @return string
	 */
	public static function get_authorization() {
		return base64_encode( self::get_username() . ':' . self::get_password() ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Generate Headers for API requests.
	 * @param string $content_type
	 *
	 * @return array
	 */
	protected static function get_headers( $content_type = 'application/json', $api_service = null, $payload = array() ) {

		return apply_filters(
			'woocommerce_bluesnap_request_headers',
			array(
				'Authorization'    => 'Basic ' . self::get_authorization(),
				'bluesnap-version' => self::get_api_version( $api_service, $payload ),
				'Content-Type'     => $content_type,
				'Accept'           => $content_type,
			),
			$api_service,
			$payload
		);
	}

	/**
	 * Return correct API version depending on what we are trying to do.
	 *
	 * @param string $api_service
	 * @param array $payload
	 * @return string
	 */
	protected static function get_api_version( $api_service, $payload ) {

		$api_version = self::API_VERSION;

		if ( self::API_ALT_TRANSACTION === $api_service ) {
			$api_version = self::API_ACH_VERSION;
		}

		if ( false !== strpos( $api_service, self::VAULTED_SHOPPERS ) ) {
			$api_version = self::API_ACH_VERSION;
		}

		if ( false !== strpos( $api_service, self::SUBSCRIPTIONS ) && isset( $payload['paymentSource']['ecpInfo'] ) ) {
			$api_version = self::API_ACH_VERSION;
		}

		if ( false !== strpos( $api_service, self::SUBSCRIPTIONS_ONDEMAND ) && isset( $payload['paymentSource']['ecpInfo'] ) ) {
			$api_version = self::API_ACH_VERSION;
		}

		return $api_version;
	}

	/**
	 * Get if it is sandbox from settings.
	 * @return bool
	 */
	public static function is_sandbox() {
		return ( ! empty( WC_Bluesnap()->get_option( 'testmode' ) ) && 'yes' === WC_Bluesnap()->get_option( 'testmode' ) ) ? true : false;
	}

	/**
	 * Get API domain depending on mode.
	 * @return string
	 */
	public static function get_domain() {
		return ( self::is_sandbox() ) ? self::API_DOMAIN_SANDBOX : self::API_DOMAIN_PRODUCTION;
	}

	/**
	 * Request to BlueSnap API.
	 * @param string $api_service
	 * @param string $method
	 * @param null $payload
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	protected static function request( $api_service, $method = 'POST', $payload = array() ) {
		$url  = self::get_domain() . self::ENDPOINT_API . $api_service;
		$args = array(
			'method'  => $method,
			'headers' => self::get_headers( 'application/json', $api_service, $payload ),
			'body'    => apply_filters( 'woocommerce_bluesnap_request_body', self::maybe_json_encode( $payload ) ),
			'timeout' => 70,
		);

		if ( 'POST' === $method ) {
			$response = wp_safe_remote_post( $url, $args );
		} elseif ( 'GET' === $method ) {
			$response = wp_safe_remote_get( $url, $args );
		} else {
			$response = wp_safe_remote_request( $url, $args );
		}

		// Logging request
		WC_Bluesnap_Logger::log_request( $url, $args, 'debug' );

		// Logging responses
		WC_Bluesnap_Logger::log_response( $response, 'debug' );

		if ( is_wp_error( $response ) || $response['response']['code'] < 200 || $response['response']['code'] > 300 ) {
			self::handle_error_response( $response );
		}

		return $response;
	}

	/**
	 * BlueSnap API Call to get token.
	 *
	 * @return mixed
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function request_hosted_field_token( $vaulted_shopper_id = null ) {
		$response = self::request( self::API_HPF_TOKEN . ( ! empty( $vaulted_shopper_id ) ? '?shopperId=' . $vaulted_shopper_id : '' ), 'POST' );
		$location = explode( '/', $response['headers']['location'] );
		return array_pop( $location );
	}

	/**
	 * @param $payload
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function create_transaction( $payload, $method = 'POST' ) {
		$transaction = self::request( self::API_TRANSACTION, $method, $payload );
		return json_decode( $transaction['body'], true );
	}

	/**
	 * @param $payload
	 *
	 * Retrieve a transaction from the API
	 * https://developers.bluesnap.com/v8976-JSON/docs/retrieve
	 *
	 * @param string $transaction_id
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function create_alt_transaction( $payload, $method = 'POST' ) {
		$transaction = self::request( self::API_ALT_TRANSACTION, $method, $payload );
		return json_decode( $transaction['body'], true );
	}

	public static function retrieve_alt_transaction( $transaction_id ) {
		$query    = self::API_ALT_TRANSACTION . '/' . $transaction_id;
		$response = self::request( $query, 'GET', array() );
		return json_decode( $response['body'], true );
	}

	/**
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function retrieve_transaction( $transaction_id ) {
		$query    = self::API_TRANSACTION . '/' . $transaction_id;
		$response = self::request( $query, 'GET', array() );
		return json_decode( $response['body'], true );
	}

	/**
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function create_3d_secure_token() {
		return self::request( self::API_3D_TOKEN, 'POST', array() );
	}


	/**
	 * Refund amount for given transaction.
	 * https://developers.bluesnap.com/v8976-JSON/docs/refund
	 * If response 204, refund completed.
	 *
	 * @param $transaction_id
	 * @param $amount
	 * @param $reason
	 *
	 * @return bool
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function create_refund( $transaction_id, $reason, $amount = null, $currency = null ) {
		$args = array(
			'reason'              => $reason,
			'cancelsubscriptions' => 'false',
		);
		if ( is_numeric( $amount ) && $amount > 0 ) {
			$args['amount'] = bluesnap_format_decimal( $amount, $currency );
		}
		$query    = add_query_arg(
			$args,
			str_replace( '{transaction_id}', $transaction_id, self::API_REFUND )
		);
		$response = self::request( $query, 'PUT', array() );
		return ( 204 == $response['response']['code'] ); //phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
	}

	/**
	 * Cancel Auth for give transaction
	 * https://developers.bluesnap.com/v8976-JSON/docs/auth-reversal
	 * If response 200, auth canceled.
	 *
	 * @param $transaction_id
	 *
	 * @return bool
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function cancel_auth( $transaction_id ) {
		$payload = array(
			'transactionId'       => $transaction_id,
			'cardTransactionType' => WC_Bluesnap_Gateway::AUTH_REVERSAL,
		);

		$response = self::request( self::API_TRANSACTION, 'PUT', $payload );
		return ( 200 == $response['response']['code'] ); //phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
	}

	/**
	 * https://developers.bluesnap.com/v8976-JSON/docs/retrieve-vaulted-shopper
	 * @param int $vaulted_shopper_id
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function retrieve_vaulted_shopper( $vaulted_shopper_id ) {
		$query    = self::VAULTED_SHOPPERS . '/' . $vaulted_shopper_id;
		$response = self::request( $query, 'GET', array() );
		return json_decode( $response['body'], true );
	}

	/**
	 * https://developers.bluesnap.com/v8976-JSON/docs/create-vaulted-shopper
	 * @param string $token - not used, left for compat.
	 * @param array  $payload
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function create_vaulted_shopper( $token, $payload ) {
		$response = self::request( self::VAULTED_SHOPPERS, 'POST', $payload );
		return json_decode( $response['body'], true );
	}

	/**
	 * https://developers.bluesnap.com/v8976-JSON/docs/update-vaulted-shopper
	 *
	 * @param $vaulted_shopper_id
	 * @param $token
	 * @param $first_name
	 * @param $last_name
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function update_vaulted_shopper( $vaulted_shopper_id, $payload ) {
		$query    = self::VAULTED_SHOPPERS . '/' . $vaulted_shopper_id;
		$response = self::request( $query, 'PUT', $payload );
		return json_decode( $response['body'], true );
	}

	/**
	 * https://developers.bluesnap.com/v8976-JSON/docs/update-vaulted-shopper#section-examples
	 *
	 * @param $vaulted_shopper_id
	 * @param $first_name
	 * @param $last_name
	 * @param $card_type
	 * @param $last_4_digits
	 *
	 * @return array|mixed|object
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function delete_vaulted_credit_card( $vaulted_shopper_id, $first_name, $last_name, $card_type, $last_4_digits ) {
		$query = self::VAULTED_SHOPPERS . '/' . $vaulted_shopper_id;

		$payload = array(
			'paymentSources' => array(
				'creditCardInfo' => array(
					array(
						'creditCard' => array(
							'cardType'           => $card_type,
							'cardLastFourDigits' => $last_4_digits,
						),
						'status'     => 'D',
					),
				),
			),
			'firstName'      => $first_name,
			'lastName'       => $last_name,
		);

		$response = self::request( $query, 'PUT', $payload );
		return json_decode( $response['body'], true );
	}

	/**
	 * https://developers.bluesnap.com/v8976-JSON/docs/update-vaulted-shopper#section-examples
	 *
	 * @param $vaulted_shopper_id
	 * @param $first_name
	 * @param $last_name
	 * @param $card_type
	 * @param $last_4_digits
	 *
	 * @return array|mixed|object
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function delete_vaulted_ach( $vaulted_shopper_id, $vaulted_shopper, $account_type, $public_account_number, $public_routing_number ) {
		$query = self::VAULTED_SHOPPERS . '/' . $vaulted_shopper_id;

		$payload = $vaulted_shopper;

		$payload['paymentSources'] = array(
			'ecpDetails' => array(
				array(
					'billingContactInfo' => array(
						'firstName' => $vaulted_shopper['firstName'],
						'lastName'  => $vaulted_shopper['lastName'],
					),
					'ecp'                => array(
						'accountType'         => $account_type,
						'publicAccountNumber' => $public_account_number,
						'publicRoutingNumber' => $public_routing_number,
					),
					'status'             => 'D',
				),
			),
		);

		if ( isset( $vaulted_shopper['zip'] ) ) {
			$payload['paymentSources']['ecpDetails'][0]['billingContactInfo']['zip'] = $vaulted_shopper['zip'];
		}

		$payload['vaultedShopperId'] = $vaulted_shopper_id;

		unset( $payload['lastPaymentInfo'] );

		$response = self::request( $query, 'PUT', $payload );
		return json_decode( $response['body'], true );
	}

	/**
	 * https://developers.bluesnap.com/v8976-Tools/docs/get-conversion-rates
	 * @param null|string $from
	 * @param null|string $to
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function retrieve_conversion_rate( $from = null, $to = null ) {
		$query    = self::CURRENCY_RATES;
		$query   .= ( $from ) ? '?base-currency=' . $from : '';
		$query   .= ( $to && $from ) ? '&quote-currency=' . $to : '';
		$response = self::request( $query, 'GET', array() );
		return json_decode( $response['body'], true );
	}

	/**
	 * Creates an apple wallet.
	 * https://developers.bluesnap.com/v8976-Tools/docs/create-wallet
	 *
	 * @param $validation_url
	 * @param null|string $domain_name
	 *
	 * @return array
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function create_apple_wallet( $validation_url, $domain_name = null ) {
		$domain_name = ( $domain_name ) ? $domain_name : $_SERVER['HTTP_HOST'];
		$payload     = array(
			'walletType'    => 'APPLE_PAY',
			'validationUrl' => $validation_url,
			'domainName'    => $domain_name,
		);

		$response = self::request( self::WALLETS, 'POST', $payload );
		return json_decode( $response['body'], true );
	}

	public static function create_ondemand_subscription( $payload ) {
		$transaction = self::request( self::SUBSCRIPTIONS_ONDEMAND, 'POST', $payload );
		return json_decode( $transaction['body'], true );
	}

	public static function create_ondemand_subscription_charge( $subscription_id, $payload ) {
		$query       = self::SUBSCRIPTIONS_ONDEMAND . '/' . $subscription_id;
		$transaction = self::request( $query, 'POST', $payload );
		return json_decode( $transaction['body'], true );
	}

	public static function update_subscription( $subscription_id, $payload ) {
		$query       = self::SUBSCRIPTIONS . '/' . $subscription_id;
		$transaction = self::request( $query, 'PUT', $payload );
		return json_decode( $transaction['body'], true );
	}

	/**
	 * @param WP_Error|array $error
	 *
	 * @throws WC_Bluesnap_API_Exception
	 */
	protected static function handle_error_response( $error ) {
		$error_message = ( is_wp_error( $error ) ) ? $error->get_error_message() : $error['body'];
		WC_Bluesnap_Logger::log( $error_message, 'error' );
		throw new WC_Bluesnap_API_Exception( $error );
	}

	/**
	 * Retrieves CDN Hosted Payment Fields JavaScript file.
	 *
	 * @return string
	 */
	public static function get_hosted_payment_js_url() {
		return self::get_domain() . self::HOSTED_PAYMENT_JS_LIBRARY;
	}

	/**
	 * Retrieves CDN Hosted Payment Fields JavaScript file.
	 *
	 * @return string
	 */
	public static function get_hosted_payment_js_version() {
		return self::HOSTED_PAYMENT_JS_LIBRARY_VERSION;
	}

	/**
	 * @param $data
	 *
	 * @return string|array
	 */
	private static function maybe_json_encode( $data ) {
		return ( is_array( $data ) && ! empty( $data ) ) ? wp_json_encode( $data ) : $data;
	}

}
