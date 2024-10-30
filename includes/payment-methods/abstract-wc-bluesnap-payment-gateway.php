<?php // @codingStandardsIgnoreLine
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract class that will be inherited by all payment methods.
 *
 * @extends WC_Abstract_Bluesnap_Payment_Gateway
 *
 * @since 1.0.0
 */
abstract class WC_Abstract_Bluesnap_Payment_Gateway extends WC_Payment_Gateway_CC {

	const TOKEN_DURATION   = 45 * MINUTE_IN_SECONDS;
	const AUTH_AND_CAPTURE = 'AUTH_CAPTURE';
	const AUTH_ONLY        = 'AUTH_ONLY';
	const CAPTURE          = 'CAPTURE';
	const AUTH_REVERSAL    = 'AUTH_REVERSAL';
	/**
	 * Notices (array)
	 * @var array
	 */
	public $notices = array();

	/**
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * Is logging active?
	 *
	 * @var bool
	 */
	public $logging;

	/**
	 * @var string
	 */
	public $api_username;

	/**
	 * @var string
	 */
	public $api_password;

	/**
	 * @var bool
	 */
	public $_3D_secure; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

	/**
	 * @var bool
	 */
	public $saved_cards;

	/**
	 * @var string
	 */
	public $fraud_id;

	/**
	 * @var string
	 */
	public $merchant_id;

	/**
	 * @var string
	 */
	public $soft_descriptor;

	/**
	 * @var bool
	 */
	public $capture_charge;

	/**
	 * @var bool
	 */
	public $rendered_fraud_iframe;

	/**
	 * Error codes returned by JS library.
	 * @return array
	 */
	protected function return_js_error_codes() {
		return WC_Bluesnap_Errors::get_hpf_errors();
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = require WC_Bluesnap()->plugin_path() . '/includes/admin/bluesnap-settings.php';
	}

	/**
	 * Enqueue Hosted Payment fields JS library only when needed.
	 */
	public function payment_scripts() {

		if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_add_payment_method_page() && ! is_checkout_pay_page() ) {
			return;
		}

		if ( 'no' == $this->enabled ) {
			return;
		}

		wp_enqueue_script( 'hpf_bluesnap', WC_Bluesnap_API::get_hosted_payment_js_url(), false, null, true );
	}

	/**
	 * If gateway is not active, don't enqueue frontend script.
	 * @param $arg
	 *
	 * @return array
	 */
	public function enqueue_payment_frontend_script( $arg ) {
		if ( 'no' == $this->enabled ) {
			return array();
		}
		return $arg;
	}

	/**
	 * Filter to localize data on WC frontend JS.
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public function localize_payment_frontend_script( $data ) {
		if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_add_payment_method_page() && ! is_checkout_pay_page() ) {
			return $data;
		}

		if ( 'no' == $this->enabled ) {
			return $data;
		}

		$data['token']            = self::get_hosted_payment_field_token();
		$data['generic_card_url'] = WC_Bluesnap()->images_url( 'generic-card.png' );
		$data['errors']           = $this->return_js_error_codes();
		$data['wc_ajax_url']      = WC_AJAX::get_endpoint( '%%endpoint%%' );
		$data['domain']           = WC_Bluesnap_API::get_domain();
		$data['images_url']       = WC_Bluesnap()->images_url( '' );
		$data['_3d_secure']       = (int) $this->_3D_secure; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$order_id                 = get_query_var( 'order-pay', 0 );
		$order                    = $order_id ? wc_get_order( $order_id ) : false;
		if ( is_a( $order, 'WC_Order' ) ) {
			$data['total_amount'] = bluesnap_format_decimal( apply_filters( 'bluesnap_3ds_total_amount', $order->get_total() ), $order->get_currency() );
			$data['currency']     = $order->get_currency();
		} else {
			$data['total_amount'] = bluesnap_format_decimal( apply_filters( 'bluesnap_3ds_total_amount', WC()->cart->get_total( false ) ) );
			$data['currency']     = get_woocommerce_currency();
		}
		$data['billing_first_name'] = WC()->customer->get_billing_first_name( 'db' );
		$data['billing_last_name']  = WC()->customer->get_billing_last_name( 'db' );
		$data['billing_country']    = WC()->customer->get_billing_country( 'db' );
		$data['billing_state']      = WC()->customer->get_billing_state( 'db' );
		$data['billing_city']       = WC()->customer->get_billing_city( 'db' );
		$data['billing_address_1']  = WC()->customer->get_billing_address_1( 'db' );
		$data['billing_address_2']  = WC()->customer->get_billing_address_2( 'db' );
		$data['billing_postcode']   = WC()->customer->get_billing_postcode( 'db' );
		$data['billing_email']      = WC()->customer->get_billing_email( 'db' );
		$data['is_sandbox']         = (int) WC_Bluesnap_API::is_sandbox();
		$data['stokens']            = WC_Bluesnap_Token::get_user_saved_tokens();

		return $data;
	}


	/**
	 * Checks if 3D is active on bluesnap end, if so, allows 3D in our integration, otherwise deactivate.
	 *
	 * @param $is_post
	 *
	 * @return bool
	 */
	public function filter_before_save( $is_post ) {
		if ( $is_post ) {
			if ( ! empty( $_POST['woocommerce_bluesnap_soft_descriptor'] ) ) { // WPCS: CSRF ok.
				$re = '/[^a-z0-9\ \&\,\.\-\#]/mi';
				$_POST['woocommerce_bluesnap_soft_descriptor'] = preg_replace( $re, '', $_POST['woocommerce_bluesnap_soft_descriptor'] ); // WPCS: CSRF ok.
				if ( strlen( $_POST['woocommerce_bluesnap_soft_descriptor'] ) > 20 ) { // WPCS: CSRF ok.
					$_POST['woocommerce_bluesnap_soft_descriptor'] = substr( $_POST['woocommerce_bluesnap_soft_descriptor'], 0, 20 ); // WPCS: CSRF ok.
					WC_Admin_Settings::add_error( __( 'The Soft Descriptor setting was trimmed to 20 characters.', 'woocommerce-bluesnap-gateway' ) );
				}
			} else {
				unset( $_POST['woocommerce_bluesnap_enabled'] );
				WC_Admin_Settings::add_error( __( 'Soft descriptor is required. Disabling the gateway.', 'woocommerce-bluesnap-gateway' ) );
			}
		}
		return $is_post;
	}

	/**
	 * Get gateway icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		//return apply_filters( 'woocommerce_gateway_icon', 'BlueSnap', $this->id );
	}

	public function add_order_success_note( $order, $txid ) {
		/* translators: transaction id */
		$message = sprintf( __( 'Bluesnap transaction complete (Transaction ID: %s)', 'woocommerce-bluesnap-gateway' ), $txid );
		$order->add_order_note( $message );
	}

	protected function get_payer_info_from_source( $customer_source, $type = 'cardHolderInfo' ) {
		$address1key = 'cardHolderInfo' == $type ? 'address' : 'address1';
		$data        = array(
			'firstName'   => $customer_source->get_billing_first_name( 'db' ),
			'lastName'    => $customer_source->get_billing_last_name( 'db' ),
			'companyName' => $customer_source->get_billing_company( 'db' ),
			'email'       => $customer_source->get_billing_email( 'db' ),
			'country'     => $customer_source->get_billing_country( 'db' ),
			'state'       => '',
			$address1key  => $customer_source->get_billing_address_1( 'db' ),
			'address2'    => $customer_source->get_billing_address_2( 'db' ),
			'city'        => $customer_source->get_billing_city( 'db' ),
			'zip'         => $customer_source->get_billing_postcode( 'db' ),
		);
		if ( in_array( $data['country'], array( 'US', 'CA' ) ) ) {
			$data['state'] = $customer_source->get_billing_state( 'db' );
		}
		return $data;
	}

	protected function get_base_payload_object( $customer_source ) {
		$user_id           = 0;
		$forced_shopper_id = false;

		if ( is_a( $customer_source, 'WC_Customer' ) ) {
			$user_id = $customer_source->get_id();
		} elseif ( is_a( $customer_source, 'WC_Order' ) ) {
			$user_id = $customer_source->get_customer_id( 'db' );
		}

		if ( is_a( $customer_source, 'WC_Subscription' ) && $customer_source->get_parent_id() ) {
			$subscription_order = wc_get_order( $customer_source->get_parent_id() );
			$old_shopper_id     = $subscription_order ? $subscription_order->get_meta( '_bluesnap_shopper_id' ) : false;
			$forced_shopper_id  = empty( $old_shopper_id ) ? false : $old_shopper_id;
		}

		$vaulted_shopper = new WC_Bluesnap_Shopper( $user_id, $forced_shopper_id );

		$ret               = (object) array(
			'type'       => 'unknown',
			'payload'    => array(),
			'shopper_id' => $vaulted_shopper->get_bluesnap_shopper_id(),
			'shopper'    => $vaulted_shopper,
			'saveable'   => false,
		);
		$ret->payer_info   = $this->get_payer_info_from_source( $customer_source );
		$ret->billing_info = $this->get_payer_info_from_source( $customer_source, 'billingContactInfo' );
		$ret->payload      = array(
			'vaultedShopperId' => $ret->shopper_id,
		);

		if ( $this->get_fraud_id() ) {
			$ret->payload['transactionFraudInfo'] = array(
				'fraudSessionId'   => $this->get_fraud_id( true ),
				'shopperIpAddress' => WC_Geolocation::get_ip_address(),
			);
		}

		if ( ! empty( $this->soft_descriptor ) ) {
			$ret->payload['softDescriptor'] = $this->soft_descriptor;
		}
		return $ret;
	}

	protected function save_payment_method_selected() {
		return isset( $_POST[ 'wc-' . $this->id . '-new-payment-method' ] ) && ( $_POST[ 'wc-' . $this->id . '-new-payment-method' ] ); // WPCS: CSRF ok.
	}

	protected function should_force_save_payment_method( $order ) {
		return apply_filters( 'wc_' . $this->id . '_save_payment_method', false, $order->get_id() );
	}

	private function adapt_vaulted_shopper_payload( $source_data ) {

		$payload = $source_data->payload;

		if ( ! empty( $payload['pfToken'] ) ) {
			$payload_source = array(
				'paymentSources' => array(
					'creditCardInfo' => array(
						array(
							'pfToken'            => $payload['pfToken'],
							'billingContactInfo' => $source_data->billing_info,
						),
					),
				),
			);
		} elseif ( ! empty( $payload['ecpTransaction'] ) ) {
			$payload_source = array(
				'paymentSources' => array(
					'ecpDetails' => array(
						array(
							'billingContactInfo' => $source_data->billing_info,
							'ecp'                => $payload['ecpTransaction'],
						),
					),
				),
			);
		} elseif ( ! empty( $payload['paymentSource']['ecpInfo'] ) ) {
			$payload_source = array(
				'paymentSources' => array(
					'ecpDetails' => array(
						array(
							'billingContactInfo' => $source_data->billing_info,
							'ecp'                => $payload['paymentSource']['ecpInfo']['ecp'],
						),
					),
				),
			);
		}

		$payload = array_merge(
			$payload,
			$payload_source
		);

		// Merge in Payer Info
		if ( isset( $payload['cardHolderInfo'] ) ) {
			$payload = array_merge(
				$payload,
				$payload['cardHolderInfo']
			);
		} elseif ( isset( $source_data->payer_info ) ) {
			$payload = array_merge(
				$payload,
				$source_data->payer_info
			);
		}

		unset( $payload['vaultedShopperId'] );
		unset( $payload['pfToken'] );
		unset( $payload['cardHolderInfo'] );
		unset( $payload['storeCard'] );
		unset( $payload['storeCard'] );
		unset( $payload['ecpTransaction'] );
		unset( $payload['paymentSource'] );
		unset( $payload['authorizedByShopper'] );
		unset( $payload['payerInfo'] );

		return $payload;
	}

	public function handle_add_posted_shopper_method( $source_data ) {
		$vaulted_shopper_id = $source_data->shopper_id;
		if ( $vaulted_shopper_id ) {
			// update
			$vaulted_shopper = WC_Bluesnap_API::update_vaulted_shopper(
				$vaulted_shopper_id,
				$this->adapt_vaulted_shopper_payload( $source_data )
			);
		} else {
			//new vaulted
			$vaulted_shopper = WC_Bluesnap_API::create_vaulted_shopper(
				null,
				$this->adapt_vaulted_shopper_payload( $source_data )
			);

			$source_data->shopper->set_bluesnap_shopper_id( $vaulted_shopper['vaultedShopperId'] );
		}

		if ( isset( $source_data->card_info ) && $source_data->card_info['last4Digits'] && $source_data->card_info['ccType'] ) {
			$last_digits = $source_data->card_info['last4Digits'];
			$source_type = $source_data->card_info['ccType'];

		} elseif ( isset( $source_data->payload['ecpTransaction'] ) && isset( $source_data->payload['ecpTransaction']['accountNumber'] ) && $source_data->payload['ecpTransaction']['accountType'] ) {

			$last_digits = substr( $source_data->payload['ecpTransaction']['accountNumber'], -5 );
			$source_type = $source_data->payload['ecpTransaction']['accountType'];

		} elseif ( $source_data->payload['paymentSource'] && $source_data->payload['paymentSource']['ecpInfo']['ecp']['accountNumber'] && $source_data->payload['paymentSource']['ecpInfo']['ecp']['accountType'] ) {

			$last_digits = substr( $source_data->payload['paymentSource']['ecpInfo']['ecp']['accountNumber'], -5 );
			$source_type = $source_data->payload['paymentSource']['ecpInfo']['ecp']['accountType'];

		}

		return WC_Bluesnap_Token::set_payment_token_from_vaulted_shopper(
			$vaulted_shopper,
			$last_digits,
			$source_type
		);
	}

	/**
	 * Gets Hosted Payment Field Token either from Session (cached) or from BlueSnap API.
	 * If Token is expired, refresh it.
	 *
	 * @return null|string
	 */
	public static function get_hosted_payment_field_token( $clear = false ) {
		$expiration = WC()->session->get( 'hpf_token_expiration' );

		if ( time() < $expiration ) {
			$token = WC()->session->get( 'hpf_token' );
			if ( $clear ) {
				self::hpf_clean_transaction_token_session();
			}
			return $token;
		}

		$token      = null;
		$shopper_id = null;

		try {
			if ( is_user_logged_in() ) {
				$shopper    = new WC_Bluesnap_Shopper();
				$shopper_id = $shopper->get_bluesnap_shopper_id();
			}

			$token = WC_Bluesnap_API::request_hosted_field_token( $shopper_id );
			WC()->session->set( 'hpf_token', $token );
			WC()->session->set( 'hpf_token_expiration', time() + self::TOKEN_DURATION );
		} catch ( WC_Bluesnap_API_Exception $e ) {
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( __( 'Error creating BlueSnap token', 'woocommerce-bluesnap-gateway' ), 'error' );
		}
		return $token;
	}


	/**
	 * Set Token expiration in the past to force creation of new token if needed.
	 */
	public static function hpf_clean_transaction_token_session() {
		if ( empty( WC()->session ) ) {
			return;
		}

		WC()->session->set( 'hpf_token_expiration', time() - self::TOKEN_DURATION );
	}


	/**
	 * Refresh Token and return to checkout form.
	 */
	public static function hpf_maybe_reset_transaction_token_session() {

		self::hpf_clean_transaction_token_session();

		$token       = self::get_hosted_payment_field_token();
		$status_code = empty( $token ) ? 404 : 200;

		wp_send_json( $token, $status_code );
	}


	/**
	 * @return bool
	 */
	public function is_save_card_available() {
		return $this->supports( 'tokenization' ) && is_checkout() && $this->saved_cards;
	}

	/**
	 * If payment is coming from a saved payment token, it will give the index.
	 * @return int|false
	 */
	protected function get_id_saved_payment_token_selected() {
		return ( isset( $_POST[ 'wc-' . $this->id . '-payment-token' ] ) ) ? filter_var( $_POST[ 'wc-' . $this->id . '-payment-token' ], FILTER_SANITIZE_NUMBER_INT ) : false; // WPCS: CSRF ok.
	}


	public function get_fraud_id( $clean = false ) {
		if ( ! WC()->session ) {
			return;
		}

		$fraud_id = WC()->session->bluesnap_fraud_id;
		if ( $clean ) {
			unset( WC()->session->bluesnap_fraud_id );
		}
		return $fraud_id;
	}

	public function set_fraud_id() {
		if ( ! WC()->session ) {
			return;
		}

		$fraud_id                        = md5( uniqid( '', true ) );
		WC()->session->bluesnap_fraud_id = $fraud_id;
		return $fraud_id;
	}

	public function can_refund_order( $order ) {
		$can = parent::can_refund_order( $order );
		if ( ! $can ) {
			return $can;
		}

		if ( 0 >= $order->get_total() - $order->get_total_refunded() ) {
			return false;
		}

		return $can;
	}

	/**
	 * Use a custom fragment to returned the clean Cart total in order to replaced the localized variable.
	 *
	 * @param array $fragments
	 * @return array
	 */
	public function relocalize_cart_total( $fragments ) {

		if ( ! WC()->cart ) {
			return $fragments;
		}

		$fragments['#bluesnap_relocalized_cart_data'] = wp_json_encode(
			array(
				'total'    => bluesnap_format_decimal( apply_filters( 'bluesnap_3ds_total_amount', WC()->cart->get_total( false ) ) ),
				'currency' => get_woocommerce_currency(),
			)
		);

		return $fragments;
	}


	public function render_fraud_kount_iframe() {
		if ( $this->rendered_fraud_iframe ) {
			return;
		}
		$this->rendered_fraud_iframe = true;
		woocommerce_bluesnap_gateway_get_template(
			'fraud-kount-iframe.php',
			array(
				'domain'       => WC_Bluesnap_API::get_domain(),
				'fraud_id'     => $this->get_fraud_id(),
				'developer_id' => $this->merchant_id,
			)
		);
	}


	/**
	 * Set order status and add an order notice with the error message as presented to the customer.
	 *
	 * @param WP_Error $e
	 * @param WC_Order $order
	 * @return void
	 */
	public function handle_failed_payment( $e, $order ) {

		$order_note = __( 'Error processing payment. Reason: ', 'woocommerce-bluesnap-gateway' ) . $e->getMessage();

		if ( ! $order->has_status( 'failed' ) ) {
			$order->update_status( 'failed', $order_note );
		} else {
			$order->add_order_note( $order_note );
		}
	}
}
