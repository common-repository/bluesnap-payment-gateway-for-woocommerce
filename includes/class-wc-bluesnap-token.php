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
 * Class WC_Bluesnap_Token
 */
class WC_Bluesnap_Token {
	/**
	 * @var WC_Bluesnap_Token
	 */
	private static $_this;

	/**
	 * Bluesnap to wc type
	 */
	const SUPPORTED_CC_TYPES = array(
		'mastercard' => 'mastercard',
		'visa'       => 'visa',
		'discover'   => 'discover',
		'amex'       => 'american express',
		'jcb'        => 'jcb',
		'diners'     => 'diners',
	);

	/**
	 * @var string
	 */
	protected static $gateway_id;

	/**
	 * @var WC_Payment_Token_Bluesnap_CC
	 */
	public static $wc_token;

	/**
	 * WC_Bluesnap_Token constructor.
	 */
	public function __construct() {
		self::$_this = $this;
		add_action( 'wc_gateway_bluesnap_delete_cc_from_my_account', array( __CLASS__, 'woocommerce_payment_token_cc_deleted' ), 10, 3 );
		add_action( 'wc_gateway_bluesnap_delete_ach_from_my_account', array( __CLASS__, 'woocommerce_payment_token_ach_deleted' ), 10, 3 );
		add_filter( 'woocommerce_payment_methods_list_item', array( __CLASS__, 'get_saved_bluesnap_cc_tokens' ), 10, 2 );
		add_filter( 'woocommerce_payment_methods_list_item', array( __CLASS__, 'get_saved_bluesnap_ach_tokens' ), 10, 2 );
	}

	/**
	 * Public access to instance object.
	 *
	 * @param $gateway_id
	 * @param bool $token_id
	 *
	 * @return WC_Bluesnap_Token
	 */
	public static function get_instance( $gateway_id, $token_id = false ) {
		self::$gateway_id = $gateway_id;
		self::set_wc_token( $token_id );
		return self::$_this;
	}

	public static function get_token() {
		return self::$wc_token;
	}

	/**
	 * @return false|string
	 */
	public function get_card_type() {
		$cc_type = self::$wc_token->get_card_type();
		return self::get_bluesnap_card_type( $cc_type );
	}

	/**
	 * @return string
	 */
	public function get_last4() {
		return self::$wc_token->get_last4();
	}

	/**
	 * @return string
	 */
	public function get_exp() {
		return self::$wc_token->get_expiry_month() . '/' . self::$wc_token->get_expiry_year();
	}

	/**
	 * Given a transaction, save card on Payment Token API.
	 *
	 * @param array $transaction
	 * @param int $vaulted_shopper_id
	 *
	 * @return int|bool
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function set_payment_token_from_transaction( $transaction, $vaulted_shopper_id ) {
		$vaulted_shopper = WC_Bluesnap_API::retrieve_vaulted_shopper( $vaulted_shopper_id );

		$last_digits = isset( $transaction['creditCard'] ) ? $transaction['creditCard']['cardLastFourDigits'] : $transaction['ecpTransaction']['publicAccountNumber'];
		$source_type = isset( $transaction['creditCard'] ) ? $transaction['creditCard']['cardType'] : $transaction['ecpTransaction']['accountType'];

		return self::set_payment_token_from_vaulted_shopper(
			$vaulted_shopper,
			$last_digits,
			$source_type
		);
	}

	/**
	 * @param array $vaulted_shopper
	 * @param string $last_four_digits
	 * @param string $cc_type
	 * @param int $user_id
	 *
	 * @return bool|int
	 */
	public static function set_payment_token_from_vaulted_shopper( $vaulted_shopper, $last_four_digits, $cc_type, $user_id = null ) {

		$payment_sources = array();

		if ( ! empty( $vaulted_shopper['paymentSources']['creditCardInfo'] ) ) {
			$payment_sources = array_merge( $payment_sources, $vaulted_shopper['paymentSources']['creditCardInfo'] );
		}

		if ( ! empty( $vaulted_shopper['paymentSources']['ecpDetails'] ) ) {
			$payment_sources = array_merge( $payment_sources, $vaulted_shopper['paymentSources']['ecpDetails'] );
		}

		foreach ( $payment_sources as $payment_source ) {

			if ( ! isset( $payment_source['creditCard'] ) && ! isset( $payment_source['ecp'] ) ) {
				continue;
			}

			if ( isset( $payment_source['creditCard'] ) && ( $payment_source['creditCard']['cardLastFourDigits'] !== $last_four_digits || $payment_source['creditCard']['cardType'] !== $cc_type ) ) {
				continue;
			}

			if ( isset( $payment_source['ecp'] ) && ( $payment_source['ecp']['publicAccountNumber'] !== $last_four_digits || $payment_source['ecp']['accountType'] !== $cc_type ) ) {
				continue;
			}

			return self::create_wc_token( $payment_source, $user_id );
		}

		return false;
	}

	public static function create_wc_token( $source, $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();

		if ( isset( $source['cardType'] ) ) {
			return self::create_wc_token_cc( $source, $user_id );
		} elseif ( isset( $source['creditCard'] ) ) {
			return self::create_wc_token_cc( $source['creditCard'], $user_id );
		} elseif ( isset( $source['ecp'] ) ) {
			return self::create_wc_token_ach( $source['ecp'], $user_id );
		} else {
			return false;
		}
	}

	public static function create_wc_token_cc( $cc, $user_id = null ) {
		$token = new WC_Payment_Token_Bluesnap_CC();
		$token->set_token( md5( wp_json_encode( $cc ) ) );
		$token->set_gateway_id( WC_BLUESNAP_GATEWAY_ID );
		$token->set_card_type( self::SUPPORTED_CC_TYPES[ strtolower( $cc['cardType'] ) ] );
		$token->set_last4( $cc['cardLastFourDigits'] );
		$token->set_expiry_month( $cc['expirationMonth'] );
		$token->set_expiry_year( $cc['expirationYear'] );
		$token->set_user_id( $user_id );
		return $token->save();

	}

	public static function create_wc_token_ach( $ach, $user_id = null ) {
		$public_account_number = isset( $ach['publicAccountNumber'] ) ? $ach['publicAccountNumber'] : substr( $ach['accountNumber'], -5 );
		$public_routing_number = isset( $ach['publicRoutingNumber'] ) ? $ach['publicRoutingNumber'] : substr( $ach['routingNumber'], -5 );

		$token = new WC_Payment_Token_Bluesnap_ACH();
		$token->set_token( md5( wp_json_encode( $ach ) ) );
		$token->set_gateway_id( WC_BLUESNAP_ACH_GATEWAY_ID );
		$token->set_account_type( $ach['accountType'] );
		$token->set_public_account_number( $public_account_number );
		$token->set_public_routing_number( $public_routing_number );
		$token->set_user_id( $user_id );
		return $token->save();
	}

	/**
	 * @param string $cc_type
	 *
	 * @return bool
	 */
	public static function is_cc_type_supported( $cc_type ) {
		return ( null !== self::SUPPORTED_CC_TYPES[ strtolower( $cc_type ) ] );
	}

	/**
	 * @param $wc_card_type
	 *
	 * @return false|string
	 */
	protected static function get_bluesnap_card_type( $wc_card_type ) {
		return strtoupper( array_search( strtolower( $wc_card_type ), self::SUPPORTED_CC_TYPES ) );
	}

	/**
	 * Get Customer Tokens for the given gateway or all BlueSnap gateways.
	 *
	 * @return array
	 */
	protected static function get_customer_tokens( $user_id = null, $gateway_id = null ) {
		$user_id        = $user_id ? $user_id : get_current_user_id();
		$customer_token = array();
		$tokens         = WC_Payment_Tokens::get_customer_tokens( $user_id );
		$gateway_ids    = $gateway_id ? array( $gateway_id ) : array(
			WC_BLUESNAP_GATEWAY_ID,
			WC_BLUESNAP_ACH_GATEWAY_ID,
		);

		foreach ( $tokens as $token ) {
			if ( in_array( $token->get_gateway_id(), $gateway_ids, true ) ) {
				$customer_token[] = $token;
			}
		}
		return $customer_token;
	}

	/**
	 * @param $token
	 */
	public static function set_wc_token( $token, $type = 'WC_Payment_Token_Bluesnap_CC' ) {
		if ( is_a( $token, $type ) ) {
			self::$wc_token = $token;
		} else {
			self::$wc_token = new $type( $token );
		}
		return self::$_this;
	}

	/**
	 * @param string $token_id
	 * @param WC_Payment_Token_Bluesnap_CC $token
	 *
	 * @return bool
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function woocommerce_payment_token_cc_deleted( $ret, $token, $force_delete ) {
		if ( 'bluesnap' !== $token->get_gateway_id() ) {
			return $ret;
		}

		$shopper            = new WC_Bluesnap_Shopper();
		$vaulted_shopper_id = $shopper->get_bluesnap_shopper_id();

		try {
			$vaulted_shopper = WC_Bluesnap_API::retrieve_vaulted_shopper( $vaulted_shopper_id );
			$token_instance  = self::set_wc_token( $token );

			WC_Bluesnap_API::delete_vaulted_credit_card(
				$vaulted_shopper_id,
				$vaulted_shopper['firstName'],
				$vaulted_shopper['lastName'],
				strtoupper( $token_instance->get_card_type() ),
				$token_instance->get_last4()
			);
		} catch ( WC_Bluesnap_API_Exception $e ) {
			// Avoid blank screen.
			// There is not much to do, since token is already gone from DB.
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return false;
		}
	}

	/**
	 * @param string $token_id
	 * @param WC_Payment_Token_Bluesnap_ACH $token
	 *
	 * @return bool
	 * @throws WC_Bluesnap_API_Exception
	 */
	public static function woocommerce_payment_token_ach_deleted( $ret, $token, $force_delete ) {
		if ( WC_BLUESNAP_ACH_GATEWAY_ID !== $token->get_gateway_id() ) {
			return $ret;
		}

		$shopper            = new WC_Bluesnap_Shopper();
		$vaulted_shopper_id = $shopper->get_bluesnap_shopper_id();

		try {
			$vaulted_shopper = WC_Bluesnap_API::retrieve_vaulted_shopper( $vaulted_shopper_id );

			WC_Bluesnap_API::delete_vaulted_ach(
				$vaulted_shopper_id,
				$vaulted_shopper,
				strtoupper( $token->get_account_type() ),
				$token->get_public_account_number(),
				$token->get_public_routing_number()
			);
		} catch ( WC_Bluesnap_API_Exception $e ) {
			// Avoid blank screen.
			// There is not much to do, since token is already gone from DB.
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return false;
		}
	}

	public static function get_saved_bluesnap_cc_tokens( $item, $payment_token ) {
		if ( 'bluesnap_cc' !== strtolower( $payment_token->get_type() ) ) {
			return $item;
		}

		$card_type               = $payment_token->get_card_type();
		$item['method']['last4'] = $payment_token->get_last4();
		$item['method']['brand'] = ( ! empty( $card_type ) ? ucfirst( $card_type ) : esc_html__( 'Credit card', 'woocommerce-bluesnap-gateway' ) );
		$item['expires']         = $payment_token->get_expiry_month() . '/' . substr( $payment_token->get_expiry_year(), -2 );
		return $item;
	}

	public static function get_saved_bluesnap_ach_tokens( $item, $payment_token ) {
		if ( strtolower( WC_BLUESNAP_ACH_GATEWAY_ID ) !== strtolower( $payment_token->get_type() ) ) {
			return $item;
		}

		$card_type               = $payment_token->get_account_type();
		$item['method']['last4'] = $payment_token->get_public_account_number();
		$item['method']['brand'] = ( ! empty( $card_type ) ? ( $card_type . ' ' . esc_html__( 'account', 'woocommerce-bluesnap-gateway' ) ) : esc_html__( 'ACH/ECP', 'woocommerce-bluesnap-gateway' ) );
		return $item;
	}


	public static function refresh_user_source_tokens_from_api( $user_id, $vaulted_shopper_id ) {
		$wc_tokens       = self::get_customer_tokens( $user_id );
		$wc_token_hashes = array();
		$vaulted_shopper = WC_Bluesnap_API::retrieve_vaulted_shopper( $vaulted_shopper_id );
		$payment_sources = array();

		if ( ! empty( $vaulted_shopper['paymentSources']['creditCardInfo'] ) ) {
			$payment_sources = array_merge( $payment_sources, $vaulted_shopper['paymentSources']['creditCardInfo'] );
		}

		if ( ! empty( $vaulted_shopper['paymentSources']['ecpDetails'] ) ) {
			$payment_sources = array_merge( $payment_sources, $vaulted_shopper['paymentSources']['ecpDetails'] );
		}

		foreach ( $wc_tokens as $wc_token ) {
			$wc_token_hashes[] = $wc_token->get_token();
		}

		foreach ( $payment_sources as $payment_source ) {
			$last_digits = isset( $payment_source['creditCard'] ) ? $payment_source['creditCard']['cardLastFourDigits'] : $payment_source['ecp']['publicAccountNumber'];
			$source_type = isset( $payment_source['creditCard'] ) ? $payment_source['creditCard']['cardType'] : $payment_source['ecp']['accountType'];
			$hash        = isset( $payment_source['creditCard'] ) ? md5( wp_json_encode( $payment_source['creditCard'] ) ) : md5( wp_json_encode( $payment_source['ecp'] ) );

			if ( ! in_array( $hash, $wc_token_hashes, true ) ) {
				self::set_payment_token_from_vaulted_shopper(
					$vaulted_shopper,
					$last_digits,
					$source_type,
					$user_id
				);
			}
		}
	}

	/**
	 * Return logged user's saved tokens
	 *
	 * @return array
	 */
	public static function get_user_saved_tokens() {

		if ( ! is_user_logged_in() ) {
			return array();
		}

		$bluesnap_gateway = WC()->payment_gateways->get_available_payment_gateways();
		if ( empty( $bluesnap_gateway[ WC_BLUESNAP_GATEWAY_ID ] ) ) {
			return array();
		}

		$bluesnap_gateway = $bluesnap_gateway[ WC_BLUESNAP_GATEWAY_ID ];

		$user_tokens = $bluesnap_gateway->get_tokens();
		if ( empty( $user_tokens ) ) {
			return array();
		}

		$tokens = array();
		foreach ( $user_tokens as $key => $token ) {
			$tokens[ $key ] = array(
				'last4' => $token->get_last4(),
				'type'  => WC_Bluesnap()->get_card_type_slug( $token->get_card_type() ),
			);
		}

		return $tokens;
	}
}

new WC_Bluesnap_Token();
