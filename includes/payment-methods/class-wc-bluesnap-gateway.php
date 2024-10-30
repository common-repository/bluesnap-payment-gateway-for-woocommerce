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
class WC_Bluesnap_Gateway extends WC_Abstract_Bluesnap_Payment_Gateway {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id           = WC_BLUESNAP_GATEWAY_ID;
		$this->method_title = __( 'BlueSnap', 'woocommerce-bluesnap-gateway' );

		$this->method_description = __( 'BlueSnap Payment Gateway', 'woocommerce-bluesnap-gateway' );
		$this->supports           = array(
			'products',
			'refunds',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'pre-orders',
		);

		$this->fraud_id = $this->get_fraud_id();
		if ( empty( $this->fraud_id ) ) {
			$this->fraud_id = $this->set_fraud_id();
		}

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Needed for direct payment gateway
		$this->has_fields            = true;
		$this->rendered_fraud_iframe = false;

		$this->title           = $this->get_option( 'title' );
		$this->description     = $this->get_option( 'description' );
		$this->enabled         = $this->get_option( 'enabled' );
		$this->soft_descriptor = $this->get_option( 'soft_descriptor' );
		$this->testmode        = ( ! empty( $this->get_option( 'testmode' ) && 'yes' === $this->get_option( 'testmode' ) ) ) ? true : false;
		$this->api_username    = ! empty( $this->get_option( 'api_username' ) ) ? $this->get_option( 'api_username' ) : '';
		$this->api_password    = ! empty( $this->get_option( 'api_password' ) ) ? $this->get_option( 'api_password' ) : '';
		$this->logging         = ( ! empty( $this->get_option( 'logging' ) ) && 'yes' === $this->get_option( 'logging' ) ) ? true : false;
		$this->capture_charge  = ( ! empty( $this->get_option( 'capture_charge' ) && 'yes' === $this->get_option( 'capture_charge' ) ) ) ? true : false;
		$this->_3D_secure      = ( ! empty( $this->get_option( '_3D_secure' ) && 'yes' === $this->get_option( '_3D_secure' ) ) ) ? true : false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$this->saved_cards     = ( ! empty( $this->get_option( 'saved_cards' ) && 'yes' === $this->get_option( 'saved_cards' ) ) ) ? true : false;
		$this->merchant_id     = ! empty( $this->get_option( 'merchant_id' ) ) ? $this->get_option( 'merchant_id' ) : '';

		add_action( 'before_woocommerce_pay', array( $this, 'maybe_load_tokenization_scripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ), 1 );
		add_filter( 'woocommerce_bluesnap_gateway_enqueue_scripts', array( $this, 'enqueue_payment_frontend_script' ) );
		add_filter( 'woocommerce_bluesnap_gateway_general_params', array( $this, 'localize_payment_frontend_script' ) );
		add_filter( 'woocommerce_save_settings_checkout_' . $this->id, array( $this, 'filter_before_save' ) );
		add_action( 'wc_gateway_bluesnap_new_card_payment_success', array( $this, 'save_payment_method_to_account' ), 10, 3 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'relocalize_cart_total' ) );
		add_action( 'wc_gateway_bluesnap_process_payment_error', array( $this, 'handle_failed_payment' ), 10, 2 );

		WC_Bluesnap_Token::get_instance( $this->id );
	}

	/**
	 * Load tokenization scripts.
	 */
	public function maybe_load_tokenization_scripts() {
		if ( ! $this->supports( 'tokenization' ) ) {
			return;
		}
		if ( is_checkout() || is_add_payment_method_page() || is_checkout_pay_page() ) {
			$this->tokenization_script();
		}
	}


	/**
	 * Validate required Checkout fields:
	 * - 3DS Reference ID if it's a new or saved card.
	 * - For new card: Card Holder Name and Surname (Space-separated)
	 *
	 * BlueSnap confirmation from credential Submission.
	 *
	 * @return bool
	 */
	public function validate_fields() {

		$errors = new WP_Error();

		// Get 3D reference ID from the form
		$threeds_reference_id = ! empty( $_POST['bluesnap_3ds_reference_id'] ) ? $_POST['bluesnap_3ds_reference_id'] : ''; //phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Will verify 3D reference only if 3DS is enabled, it's not a payment change or is a payment change with a new card
		$verify_3d_reference = ( WC_Bluesnap()->is_3d_secure_enabled() && ( ! isset( $_POST['woocommerce_change_payment'] ) || 'new' === $_POST['wc-bluesnap-payment-token'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $verify_3d_reference && empty( $threeds_reference_id ) ) {
			$errors->add( 'threeds_reference_invalid', esc_html__( '3DS Reference is empty.', 'woocommerce-bluesnap-gateway' ) );
		}

		if ( ! $this->supports( 'tokenization' ) || ! $this->saved_cards || ! $this->get_id_saved_payment_token_selected() ) {

			$card_info = ! empty( $_POST['bluesnap_card_info'] ) ? json_decode( stripslashes_deep( $_POST['bluesnap_card_info'] ), true ) : array(); //phpcs:ignore WordPress.Security.NonceVerification.Missing

			if (
				empty( $card_info ) ||
				empty( $card_info['ccType'] ) ||
				empty( $card_info['last4Digits'] ) ||
				empty( $card_info['issuingCountry'] ) ||
				empty(
					$card_info['exp']
				) ) {
				$errors->add( 'card_info_invalid', esc_html__( 'Card information is invalid.', 'woocommerce-bluesnap-gateway' ) );
			}
		}

		$errors = apply_filters( 'wc_gateway_bluesnap_validate_fields', $errors );

		$errors_messages = $errors->get_error_messages();
		if ( ! empty( $errors_messages ) ) {
			foreach ( $errors_messages as $message ) {
				wc_add_notice( $message, 'error' );
			}
			return false;
		}

		return true;
	}

	protected function fetch_transaction_new_card_payload( $customer_source, $ret = null ) {
		if ( is_null( $ret ) ) {
			$ret = $this->get_base_payload_object( $customer_source );
		} else {
			$ret = (object) $ret;
		}
		$ret->type      = 'new_card';
		$ret->pf_token  = self::get_hosted_payment_field_token( true );
		$ret->card_info = json_decode( stripslashes_deep( $_POST['bluesnap_card_info'] ), true ); // WPCS: CSRF ok.
		$ret->payload   = array_merge(
			$ret->payload,
			array(
				'cardHolderInfo' => $ret->payer_info,
				'pfToken'        => $ret->pf_token,
				'storeCard'      => false,
			)
		);
		$ret->saveable  = true; // can be saved as new payment method
		return $ret;
	}

	protected function fetch_transaction_payment_method_payload( $order ) {
		$ret = $this->get_base_payload_object( $order );

		$ret = apply_filters( 'wc_gateway_bluesnap_transaction_payment_method_payload', $ret, $order );
		if ( 'unknown' === $ret->type ) {
			// If payment saved token
			$payment_method_token = $this->get_id_saved_payment_token_selected();
			if ( $payment_method_token ) {
				$token = WC_Bluesnap_Token::set_wc_token( $payment_method_token );

				$ret->type      = 'token';
				$ret->card_info = array(
					'last4Digits' => $token->get_last4(),
					'ccType'      => $token->get_card_type(),
					'exp'         => $token->get_exp(),
				);

				$ret->payload = array_merge(
					$ret->payload,
					array(
						'creditCard' => array(
							'cardLastFourDigits' => $token->get_last4(),
							'cardType'           => WC_Bluesnap()->get_card_type_slug( $token->get_card_type() ),
						),
					)
				);
				$ret->token   = $payment_method_token;
			} else {
				$ret = $this->fetch_transaction_new_card_payload( $order, $ret );
			}
		}

		if ( WC_Bluesnap()->is_3d_secure_enabled() && ! empty( $_POST['bluesnap_3ds_reference_id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ret->payload['threeDSecure'] = array( //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'threeDSecureReferenceId' => $_POST['bluesnap_3ds_reference_id'], //phpcs:ignore WordPress.Security.NonceVerification.Missing
			);
		}

		return $ret;
	}

	private function save_payment_method_soft_fail( $order, $extra_note = false ) {
		$order->add_order_note( __( 'User credit card could not be saved.', 'woocommerce-bluesnap-gateway' ) . ( $extra_note ? ' ' . $extra_note : '' ) );
		wc_add_notice( __( 'We could not save your credit card, try next time.', 'woocommerce-bluesnap-gateway' ), 'error' );
	}

	public function save_payment_method_to_account( $order, $payment_method_data, $transaction ) {
		try {
			// If customer wants to save its card or it is a subscription.
			if ( $this->is_save_card_available() ) {
				if ( $this->save_payment_method_selected() || $this->should_force_save_payment_method( $order ) ) {
					$saved_wc_token = $this->save_payment_method( $transaction );
					if ( ! $saved_wc_token ) {
						$this->save_payment_method_soft_fail( $order );
						return;
					}

					do_action( 'wc_gateway_bluesnap_save_payment_method_success', $transaction['vaultedShopperId'], $saved_wc_token, $order->get_id() );
				}
			}
		} catch ( WC_Bluesnap_API_Exception $e ) {
			$this->save_payment_method_soft_fail( $order, $e->getMessage() );
		} catch ( Exception $e ) {
			$this->save_payment_method_soft_fail( $order, $e->getMessage() );
		}
	}

	/**
	 * Process Payment.
	 * First of all and most important is to process the payment.
	 * Second if needed, save payment token card.
	 *
	 * @param int $order_id
	 * @param string $transaction_type
	 * @param null|string $override_total
	 * @param bool $payment_complete
	 *
	 * @return array
	 * @throws Exception
	 */
	public function process_payment( $order_id, $transaction_type = null, $override_total = null, $payment_complete = true ) {

		try {
			$order  = wc_get_order( $order_id );
			$amount = ( isset( $override_total ) && is_numeric( $override_total ) ) ? (float) $override_total : $order->get_total();

			$transaction_type = is_null( $transaction_type ) ? ( $this->capture_charge ? self::AUTH_AND_CAPTURE : self::AUTH_ONLY ) : $transaction_type;

			$payload = array(
				'amount'                => bluesnap_format_decimal( $amount, $order->get_currency() ),
				'currency'              => $order->get_currency(),
				'merchantTransactionId' => $order_id,
				'cardTransactionType'   => $transaction_type,
			);

			$attempt_payments = apply_filters( 'wc_gateway_bluesnap_alternate_payment', true, $order, $payload, $this );

			if ( $attempt_payments && ! $order->get_date_paid( 'edit' ) ) {
				$payment_method_data = $this->fetch_transaction_payment_method_payload( $order );
				if ( is_null( $payment_method_data ) || 'unknown' === $payment_method_data->type ) {
					throw new Exception( __( 'Something went wrong with your payment method selected.', 'woocommerce-bluesnap-gateway' ) );
				}

				$payload = array_merge( $payload, $payment_method_data->payload );
				if ( $payment_method_data->saveable && $this->is_save_card_available() ) {
					if ( $this->save_payment_method_selected() || $this->should_force_save_payment_method( $order ) ) {
						$payload['storeCard'] = true;
					}
				}
				$transaction = WC_Bluesnap_API::create_transaction( $payload );

				// Set bluesnap shopper id as soon as we have it available
				$shopper = new WC_Bluesnap_Shopper();
				if ( ! $shopper->get_bluesnap_shopper_id() ) {
					$shopper->set_bluesnap_shopper_id( $transaction['vaultedShopperId'] );
				}

				if ( self::AUTH_AND_CAPTURE !== $transaction['cardTransactionType'] ) {
					// AUTH only
					$order->update_meta_data( '_bluesnap_charge_captured', 'no' );

					/* translators: transaction id */
					$order->update_status( 'on-hold', sprintf( __( 'Bluesnap charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-bluesnap-gateway' ), $transaction['transactionId'] ) );
					$order->set_transaction_id( $transaction['transactionId'] );

				} else {
					// Captured
					$order->update_meta_data( '_bluesnap_charge_captured', 'yes' );

					if ( $payment_complete ) {
						$this->add_order_success_note( $order, $transaction['transactionId'] );
						$order->payment_complete( $transaction['transactionId'] );
					}
				}

				if ( is_callable( array( $order, 'save' ) ) ) {
					$order->save();
				}

				do_action( 'wc_gateway_bluesnap_' . $payment_method_data->type . '_payment_success', $order, $payment_method_data, $transaction );
			}

			self::hpf_clean_transaction_token_session();
			do_action( 'wc_gateway_bluesnap_process_payment_success', $order );

		} catch ( Exception $e ) {
			self::hpf_clean_transaction_token_session();
			WC()->session->set( 'refresh_totals', true ); // this triggers refresh of the checkout area
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );

			do_action( 'wc_gateway_bluesnap_process_payment_error', $e, $order );
			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		// It is a success anyways, since the order at this point is completed.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * @param $transaction
	 *
	 * @return bool
	 * @throws WC_Bluesnap_API_Exception
	 */
	public function save_payment_method( $transaction ) {
		$shopper              = new WC_Bluesnap_Shopper();
		$vaulted_shopper_id   = $shopper->get_bluesnap_shopper_id();
		$payment_method_token = WC_Bluesnap_Token::set_payment_token_from_transaction( $transaction, $vaulted_shopper_id );
		return $payment_method_token;
	}

	/**
	 * Refund process.
	 *
	 * @param int $order_id
	 * @param null $amount
	 * @param string $reason
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_transaction_id() ) {
			return false;
		}

		try {

			$captured = 'no' !== $order->get_meta( '_bluesnap_charge_captured', true );

			$reason = REFUND_REASON_PREFIX . $reason;
			if ( bluesnap_format_decimal( $order->get_total( 'db' ) ) == bluesnap_format_decimal( $amount, $order->get_currency() ) ) {
				$amount = null;
			}

			// Take care of Orders from the old plugin which are stored with the Base shop currency
			$charged_currency = $order->get_meta( '_charged_currency' );
			if ( ! empty( $charged_currency ) && $charged_currency !== $order->get_currency() ) {
				$ex_rate  = $order->get_meta( '_bsnp_ex_rate' );
				$amount   = ! empty( $ex_rate ) && ! is_null( $amount ) ? ( floatval( $ex_rate ) * $amount ) : $amount;
				$currency = $charged_currency;
			} else {
				$currency = $order->get_currency();
			}

			do_action( 'wc_gateway_bluesnap_before_refund', $order, $order->get_transaction_id() );

			if ( $captured || WC_BLUESNAP_ACH_GATEWAY_ID === $order->get_payment_method() ) {
				$refunded = WC_Bluesnap_API::create_refund( $order->get_transaction_id(), $reason, $amount, $currency );
			} else {
				$refunded = WC_Bluesnap_API::cancel_auth( $order->get_transaction_id() );
			}

			if ( $refunded && ! $captured ) {
				$order->add_order_note( __( 'Pre-Authorization Released', 'woocommerce-bluesnap-gateway' ) );
			}

			return true;
		} catch ( WC_Bluesnap_API_Exception $e ) {

			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			do_action( 'wc_gateway_bluesnap_process_refund_error', $e, $order );

			return new WP_Error( 'refund_error', sprintf( __( 'An error occurred during the refund request: %s', 'woocommerce-bluesnap-gateway' ), $e->getMessage() ) );
		}
	}



	/**
	 * Add Payment Method hook on My account.
	 *
	 * @return array
	 * @throws Exception
	 */
	public function add_payment_method() {
		if ( ! wp_verify_nonce( $_POST['woocommerce-add-payment-method-nonce'], 'woocommerce-add-payment-method' ) ) {
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}

		$card_data = $this->fetch_transaction_new_card_payload( WC()->customer );

		if ( ! WC_Bluesnap_Token::is_cc_type_supported( $card_data->card_info['ccType'] ) ) {
			wc_add_notice( __( 'Credit Card type not supported on Add Payment Methods.', 'woocommerce-bluesnap-gateway' ), 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}

		try {
			$this->handle_add_posted_shopper_method( $card_data );
			self::hpf_clean_transaction_token_session();
			return array(
				'result'   => 'success',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);

		} catch ( WC_Bluesnap_API_Exception $e ) {
			self::hpf_clean_transaction_token_session();
			WC()->session->set( 'refresh_totals', true ); // this triggers refresh of the checkout area
			WC_Bluesnap_Logger::log( $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			do_action( 'wc_gateway_bluesnap_add_payment_method_error', $e );
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}
	}

	/**
	 * Payment Form on checkout page
	 */
	public function payment_fields() {

		// Enqueue general gateway script, this ensures to make API call only when it's needed.
		wp_enqueue_script( 'woocommerce-bluesnap-gateway-general' );

		$this->render_fraud_kount_iframe();

		if ( ! empty( $this->description ) ) {
			echo apply_filters( 'wc_bluesnap_description', wpautop( wp_kses_post( $this->description ) ), $this->id ); // WPCS: XSS ok, sanitization ok.
		}

		$this->maybe_load_tokenization_scripts();

		$display_tokenization = $this->is_save_card_available();

		if ( $display_tokenization ) {
			$this->saved_payment_methods();
		}

		woocommerce_bluesnap_gateway_get_template( 'payment-fields-bluesnap.php', array( 'gateway' => $this ) );

		if ( ! apply_filters( 'wc_' . $this->id . '_hide_save_payment_method_checkbox', ! $display_tokenization ) && ! is_add_payment_method_page() ) {
			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * Linking transaction id order to BlueSnap.
	 *
	 * @param WC_Order $order
	 *
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		if ( WC_Bluesnap_API::is_sandbox() ) {
			$this->view_transaction_url = 'https://sandbox.bluesnap.com/jsp/order_locator_info.jsp?invoiceId=%s';
		} else {
			$this->view_transaction_url = 'https://bluesnap.com/jsp/order_locator_info.jsp?invoiceId=%s';
		}
		return parent::get_transaction_url( $order );
	}

	/**
	 * Error codes returned by JS library.
	 * @return array
	 */
	protected function return_js_error_codes() {
		return WC_Bluesnap_Errors::get_hpf_errors();
	}

}
